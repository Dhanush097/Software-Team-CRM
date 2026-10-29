<?php
/**
 * Lightweight, self-contained CBOR (RFC 8949) Decoder for WebAuthn Attestation & COSE Keys.
 */

class CborDecoder {
    private string $data;
    private int $offset;
    private int $length;

    public function __construct(string $data) {
        $this->data = $data;
        $this->offset = 0;
        $this->length = strlen($data);
    }

    public static function decode(string $data) {
        $decoder = new self($data);
        return $decoder->parse();
    }

    public function parse() {
        if ($this->offset >= $this->length) {
            throw new Exception("Unexpected end of CBOR buffer");
        }

        $initialByte = ord($this->data[$this->offset++]);
        $majorType = $initialByte >> 5;
        $additionalInfo = $initialByte & 0x1f;

        $val = $this->readLength($additionalInfo);

        switch ($majorType) {
            case 0: // Unsigned integer
                return $val;

            case 1: // Negative integer: -1 - val
                return -1 - $val;

            case 2: // Byte string
                $str = substr($this->data, $this->offset, $val);
                $this->offset += $val;
                return $str;

            case 3: // Text string (UTF-8)
                $str = substr($this->data, $this->offset, $val);
                $this->offset += $val;
                return $str;

            case 4: // Array
                $arr = [];
                for ($i = 0; $i < $val; $i++) {
                    $arr[] = $this->parse();
                }
                return $arr;

            case 5: // Map
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = $this->parse();
                    $v = $this->parse();
                    // Keep integer keys as integers or strings as strings
                    $map[$k] = $v;
                }
                return $map;

            case 6: // Tag
                return $this->parse();

            case 7: // Simple value / float
                if ($additionalInfo === 20) return false;
                if ($additionalInfo === 21) return true;
                if ($additionalInfo === 22) return null;
                return $val;

            default:
                throw new Exception("Unsupported CBOR major type: $majorType");
        }
    }

    private function readLength(int $additionalInfo): int {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        switch ($additionalInfo) {
            case 24:
                $b = substr($this->data, $this->offset, 1);
                $this->offset += 1;
                return ord($b);

            case 25:
                $b = substr($this->data, $this->offset, 2);
                $this->offset += 2;
                $unpacked = unpack('n', $b);
                return $unpacked[1];

            case 26:
                $b = substr($this->data, $this->offset, 4);
                $this->offset += 4;
                $unpacked = unpack('N', $b);
                return $unpacked[1];

            case 27:
                $b = substr($this->data, $this->offset, 8);
                $this->offset += 8;
                $unpacked = unpack('J', $b);
                return $unpacked[1];

            default:
                throw new Exception("Indefinite length not supported in WebAuthn credentials");
        }
    }

    public function getOffset(): int {
        return $this->offset;
    }

    public static function byteString(string $bytes): CborByteString {
        return new CborByteString($bytes);
    }

    public static function encodeHeader(int $majorType, int $val): string {
        $prefix = $majorType << 5;
        if ($val < 24) {
            return chr($prefix | $val);
        } elseif ($val <= 0xFF) {
            return chr($prefix | 24) . chr($val);
        } elseif ($val <= 0xFFFF) {
            return chr($prefix | 25) . pack('n', $val);
        } elseif ($val <= 0xFFFFFFFF) {
            return chr($prefix | 26) . pack('N', $val);
        } else {
            return chr($prefix | 27) . pack('J', $val);
        }
    }

    public static function encode($value): string {
        if ($value instanceof CborByteString) {
            return self::encodeHeader(2, strlen($value->bytes)) . $value->bytes;
        }

        if (is_int($value)) {
            if ($value >= 0) {
                return self::encodeHeader(0, $value);
            } else {
                $pos = -$value - 1;
                return self::encodeHeader(1, $pos);
            }
        }

        if (is_string($value)) {
            return self::encodeHeader(3, strlen($value)) . $value;
        }

        if (is_array($value)) {
            $isList = (array_keys($value) === range(0, count($value) - 1));
            if ($isList && count($value) > 0) {
                $out = self::encodeHeader(4, count($value));
                foreach ($value as $item) {
                    $out .= self::encode($item);
                }
                return $out;
            } else {
                $out = self::encodeHeader(5, count($value));
                foreach ($value as $k => $v) {
                    $out .= self::encode($k) . self::encode($v);
                }
                return $out;
            }
        }

        if (is_object($value)) {
            $vars = get_object_vars($value);
            $out = self::encodeHeader(5, count($vars));
            foreach ($vars as $k => $v) {
                $out .= self::encode($k) . self::encode($v);
            }
            return $out;
        }

        if (is_null($value)) {
            return chr(0xf6);
        }

        if (is_bool($value)) {
            return $value ? chr(0xf5) : chr(0xf4);
        }

        throw new Exception("Unsupported CBOR type for encoding");
    }
}

class CborByteString {
    public string $bytes;
    public function __construct(string $bytes) {
        $this->bytes = $bytes;
    }
}
