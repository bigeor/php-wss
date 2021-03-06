<?php

namespace WSSC;

class ConnectionImpl implements IConnection, ICommons {

    private $socketConnection;

    public function getConnection($sockConn) {
        $this->socketConnection = $sockConn;
        return $this;
    }

    /**
     * Closes clients socket stream
     */
    public function close() {
        if (is_resource($this->socketConnection)) {
            fclose($this->socketConnection);
        }
    }

    /**
     * This method is invoked when user implementation call $conn->send($data) 
     * writes data to the clients stream socket
     * @param stirng $data  pure decoded data from server
     */
    public function send($data) {
        fwrite($this->socketConnection, $this->encode($data));
    }

    /**
     * Encodes data before writing to the client socket stream
     * @param string $payload
     * @param string $type
     * @param boolean $masked
     * @return type
     */
    private function encode($payload, $type = self::EVENT_TYPE_TEXT, $masked = false) {
        $frameHead = [];
        $payloadLength = strlen($payload);

        switch ($type) {
            case self::EVENT_TYPE_TEXT:
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = self::ENCODE_TEXT;
                break;

            case self::EVENT_TYPE_CLOSE:
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = self::ENCODE_CLOSE;
                break;

            case self::EVENT_TYPE_PING:
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = self::ENCODE_PING;
                break;

            case self::EVENT_TYPE_PONG:
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = self::ENCODE_PONG;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > self::PAYLOAD_MAX_BITS) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), self::PAYLOAD_CHUNK);
            $frameHead[1] = ($masked === true) ? self::MASK_255 : self::MASK_127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0
            if ($frameHead[2] > self::MASK_127) {
                return ['type' => $type, 'payload' => $payload, 'error' => self::ERR_FRAME_TOO_LARGE];
            }
        } elseif ($payloadLength > self::MASK_125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), self::PAYLOAD_CHUNK);
            $frameHead[1] = ($masked === true) ? self::MASK_254 : self::MASK_126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + self::MASK_128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked === true) {
            // generate a random mask:
            $mask = [];
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, self::MASK_255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

}
