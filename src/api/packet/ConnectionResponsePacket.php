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

namespace cooldogedev\Spectrum\api\packet;

use pocketmine\utils\BinaryStream;

final class ConnectionResponsePacket extends Packet
{
    public const int PACKET_ID = PacketIds::CONNECTION_RESPONSE;

    public const int RESPONSE_SUCCESS = 0;
    public const int RESPONSE_UNAUTHORIZED = 1;
    public const int RESPONSE_FAIL = 2;

    public int $response;

    public static function create(int $response): ConnectionResponsePacket
    {
        $pk = new ConnectionResponsePacket();
        $pk->response = $response;
        return $pk;
    }

    protected function decodePayload(BinaryStream $stream): void
    {
        $this->response = $stream->getByte();
    }

    protected function encodePayload(BinaryStream $stream): void
    {
        $stream->putByte($this->response);
    }
}
