<?php

/**
 * MIT License
 *
 * Copyright (c) 2024 cooldogedev
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @auto-license
 */

declare(strict_types=1);

namespace cooldogedev\Spectrum\client;

use cooldogedev\spectral\Listener;
use cooldogedev\spectral\ServerConnection;
use cooldogedev\spectral\Stream;
use cooldogedev\spectral\util\Address;
use cooldogedev\Spectrum\client\packet\DisconnectPacket;
use cooldogedev\Spectrum\client\packet\LoginPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use Exception;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use Socket;
use function socket_read;

final class ClientListener
{
    private readonly Listener $listener;
    private int $nextId = 0;
    /**
     * @phpstan-var array<int, Client>
     */
    private array $clients = [];

    private readonly PthreadsChannelReader $mainToThread;
    private readonly SnoozeAwarePthreadsChannelWriter $threadToMain;

    public function __construct(
        private readonly SleeperHandlerEntry $sleeperHandlerEntry,
        private readonly Socket              $notificationSocket,
        private readonly ThreadSafeLogger    $logger,

        private readonly int                 $port,

        ThreadSafeArray                      $mainToThread,
        ThreadSafeArray                      $threadToMain,
    ) {
        $this->mainToThread = new PthreadsChannelReader($mainToThread);
        $this->threadToMain = new SnoozeAwarePthreadsChannelWriter($threadToMain, $this->sleeperHandlerEntry->createNotifier());
    }

    public function start(): void
    {
        $streamAcceptor = function (Address $address, Stream $stream): void {
            $identifier = $this->nextId;
            $this->nextId++;
            $stream->registerCloseHandler(fn () => $this->disconnect($identifier, true));
            $this->clients[$identifier] = new Client(
                stream: $stream,
                logger: $this->logger,
                writer: $this->threadToMain,
                id: $identifier,
            );
            $this->threadToMain->write(ProxySerializer::encode($identifier, LoginPacket::create($address->address, $address->port)));
            $this->logger->debug("Accepted client " . $this->nextId . " from " . $address->toString());
        };
        $this->listener = Listener::listen("0.0.0.0", $this->port);
        $this->listener->setConnectionAcceptor(function (ServerConnection $connection) use ($streamAcceptor): void {
            $this->logger->debug("Accepted connection from " . $connection->getRemoteAddress()->toString());
            $connection->setStreamAcceptor(fn (Stream $stream) => $streamAcceptor($connection->getRemoteAddress(), $stream));
        });
        $this->listener->registerSocket($this->notificationSocket, function (): void {
            @socket_read($this->notificationSocket, 65535);
            $this->write();
        });
    }

    public function tick(): void
    {
        $this->listener->tick();
    }

    private function write(): void
    {
        while (($out = $this->mainToThread->read()) !== null) {
            [$identifier, $decodeNeeded, $buffer] = ProxySerializer::decodeRawWithDecodeNecessity($out);
            $client = $this->clients[$identifier] ?? null;
            if ($client === null) {
                continue;
            }

            $packet = ProxyPacketPool::getInstance()->getPacket($buffer);
            if ($packet instanceof DisconnectPacket) {
                $this->disconnect($identifier, false);
                continue;
            }

            try {
                $client->write($buffer, $decodeNeeded);
            } catch (Exception $exception) {
                $this->disconnect($identifier, true);
                $this->logger->logException($exception);
            }
        }
    }

    private function disconnect(int $clientId, bool $notifyMain): void
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null) {
            return;
        }

        if ($notifyMain) {
            $this->threadToMain->write(ProxySerializer::encode($client->id, DisconnectPacket::create()));
        }
        $client->close();
        unset($this->clients[$client->id]);
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
            unset($this->clients[$client->id]);
        }
        $this->listener->close();
    }
}
