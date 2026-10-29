<?php
/**
 * Software CRM - WebAuthn & Mobile Biometric Cryptographic Helper
 * W3C Web Authentication standard / FIDO2 Level 2 compliance.
 */

require_once __DIR__ . '/cbor_decoder.php';

class WebAuthnHelper {

    public static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64url_decode(string $data): string {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function generateChallenge(int $length = 32): string {
        return self::base64url_encode(random_bytes($length));
    }

    public static function getRelyingPartyId(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Strip port if present
        $parts = explode(':', $host);
        return $parts[0];
    }

    public static function getExpectedOrigin(): string {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $scheme = $isSecure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        return "$scheme://$host";
    }

    /**
     * Validates clientDataJSON
     */
    public static function validateClientData(string $clientDataJson, string $expectedType, string $expectedChallenge): array {
        $clientData = json_decode($clientDataJson, true);
        if (!$clientData || !is_array($clientData)) {
            throw new Exception("Invalid clientDataJSON format");
        }

        if (($clientData['type'] ?? '') !== $expectedType) {
            throw new Exception("clientData type mismatch: expected $expectedType, got " . ($clientData['type'] ?? ''));
        }

        $receivedChallenge = $clientData['challenge'] ?? '';
        // Compare base64url challenges
        if (!hash_equals($expectedChallenge, $receivedChallenge)) {
            // Also test decoded bytes
            $expectedBytes = self::base64url_decode($expectedChallenge);
            $receivedBytes = self::base64url_decode($receivedChallenge);
            if (!hash_equals($expectedBytes, $receivedBytes)) {
                throw new Exception("Challenge mismatch: possible replay attack");
            }
        }

        // Validate origin - permit standard localhost / 127.0.0.1 development ports and production origin
        $origin = $clientData['origin'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!empty($origin)) {
            $parsed = parse_url($origin);
            $originHost = $parsed['host'] ?? '';
            $originPort = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $originHostPort = $originHost . $originPort;

            // Allow matching host or loopback aliases during development
            $allowedHosts = [$host, 'localhost', '127.0.0.1'];
            if (!in_array($originHost, $allowedHosts) && !in_array($originHostPort, $allowedHosts)) {
                // If not strictly matching, verify domain
                if (stripos($host, $originHost) === false) {
                    throw new Exception("Origin mismatch: origin '$origin' does not match server host '$host'");
                }
            }
        }

        return $clientData;
    }

    /**
     * Parses attestationObject during registration
     */
    public static function parseAttestationObject(string $attestationObjectBinary): array {
        $attestation = CborDecoder::decode($attestationObjectBinary);
        if (!is_array($attestation) || empty($attestation['authData'])) {
            throw new Exception("Invalid attestationObject structure");
        }

        $fmt = $attestation['fmt'] ?? 'none';
        $authData = $attestation['authData'];
        $len = strlen($authData);

        if ($len < 37) {
            throw new Exception("authData too short (< 37 bytes)");
        }

        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $up = ($flags & 0x01) !== 0; // User Present
        $uv = ($flags & 0x04) !== 0; // User Verified (Biometric / PIN)
        $at = ($flags & 0x40) !== 0; // Attested Credential Data present

        if (!$up) {
            throw new Exception("User Presence (UP) flag not set");
        }

        if (!$at) {
            throw new Exception("Attested credential data (AT) flag missing in registration authData");
        }

        $offset = 37;
        $aaguid = substr($authData, $offset, 16);
        $offset += 16;

        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;

        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;

        // Remaining bytes contain COSE Public Key
        $coseKeyBinary = substr($authData, $offset);
        $coseDecoder = new CborDecoder($coseKeyBinary);
        $coseKey = $coseDecoder->parse();

        // Convert COSE key to OpenSSL PEM format
        $publicKeyPem = self::coseKeyToPem($coseKey);

        return [
            'fmt' => $fmt,
            'rpIdHash' => bin2hex($rpIdHash),
            'flags' => $flags,
            'userPresent' => $up,
            'userVerified' => $uv,
            'signCount' => $signCount,
            'aaguid' => bin2hex($aaguid),
            'credentialId' => self::base64url_encode($credentialId),
            'credentialIdRaw' => $credentialId,
            'publicKeyPem' => $publicKeyPem,
            'coseKey' => $coseKey
        ];
    }

    /**
     * Converts COSE Public Key map to OpenSSL PEM string
     */
    public static function coseKeyToPem(array $coseKey): string {
        $kty = $coseKey[1] ?? 0;
        $alg = $coseKey[3] ?? 0;

        // 1. EC2 Key (P-256 curve, ES256 algorithm = -7)
        if ($kty === 2 || $alg === -7) {
            $x = $coseKey[-2] ?? null;
            $y = $coseKey[-3] ?? null;

            if (empty($x) || empty($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new Exception("Invalid EC2 P-256 public key coordinates");
            }

            // SubjectPublicKeyInfo DER header for id-ecPublicKey + prime256v1:
            // 30 59 30 13 06 07 2a 86 48 ce 3d 02 01 06 08 2a 86 48 ce 3d 03 01 07 03 42 00 04
            $derHeader = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
            $der = $derHeader . $x . $y;

            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        // 2. RSA Key (RS256 algorithm = -257)
        if ($kty === 3 || $alg === -257) {
            $n = $coseKey[-1] ?? null; // Modulus
            $e = $coseKey[-2] ?? null; // Exponent

            if (empty($n) || empty($e)) {
                throw new Exception("Invalid RSA public key parameters");
            }

            // Encode RSA Public Key to DER SubjectPublicKeyInfo
            $der = self::encodeRsaPublicKeyDer($n, $e);
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        throw new Exception("Unsupported COSE algorithm ($alg) / key type ($kty)");
    }

    private static function encodeRsaPublicKeyDer(string $n, string $e): string {
        // RSA sequence of n, e
        $encodeInt = function($bytes) {
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\x00" . $bytes;
            }
            $len = strlen($bytes);
            if ($len < 128) {
                $lenBytes = chr($len);
            } else {
                $lStr = ltrim(pack('N', $len), "\x00");
                $lenBytes = chr(0x80 | strlen($lStr)) . $lStr;
            }
            return "\x02" . $lenBytes . $bytes;
        };

        $nDer = $encodeInt($n);
        $eDer = $encodeInt($e);
        $rsaKeySeq = $nDer . $eDer;

        $rsaKeySeqLen = strlen($rsaKeySeq);
        if ($rsaKeySeqLen < 128) {
            $rsaKeySeqLenBytes = chr($rsaKeySeqLen);
        } else {
            $lStr = ltrim(pack('N', $rsaKeySeqLen), "\x00");
            $rsaKeySeqLenBytes = chr(0x80 | strlen($lStr)) . $lStr;
        }
        $rsaKey = "\x30" . $rsaKeySeqLenBytes . $rsaKeySeq;

        // Bit string
        $bitStringLen = strlen($rsaKey) + 1;
        if ($bitStringLen < 128) {
            $bitStringLenBytes = chr($bitStringLen);
        } else {
            $lStr = ltrim(pack('N', $bitStringLen), "\x00");
            $bitStringLenBytes = chr(0x80 | strlen($lStr)) . $lStr;
        }
        $bitString = "\x03" . $bitStringLenBytes . "\x00" . $rsaKey;

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algId = hex2bin('300d06092a864886f70d0101010500');

        $fullSeq = $algId . $bitString;
        $fullLen = strlen($fullSeq);
        if ($fullLen < 128) {
            $fullLenBytes = chr($fullLen);
        } else {
            $lStr = ltrim(pack('N', $fullLen), "\x00");
            $fullLenBytes = chr(0x80 | strlen($lStr)) . $lStr;
        }

        return "\x30" . $fullLenBytes . $fullSeq;
    }

    /**
     * Verifies WebAuthn Assertion (Authentication)
     */
    public static function verifyAssertion(
        string $authenticatorDataBinary,
        string $clientDataJson,
        string $signatureBinary,
        string $publicKeyPem,
        int $storedCounter
    ): array {
        if (strlen($authenticatorDataBinary) < 37) {
            throw new Exception("authenticatorData too short (< 37 bytes)");
        }

        $rpIdHash = substr($authenticatorDataBinary, 0, 32);
        $flags = ord($authenticatorDataBinary[32]);
        $signCount = unpack('N', substr($authenticatorDataBinary, 33, 4))[1];

        $up = ($flags & 0x01) !== 0; // User Present
        $uv = ($flags & 0x04) !== 0; // User Verified (Biometrics / PIN)

        if (!$up) {
            throw new Exception("User Presence (UP) flag not set");
        }

        // Anti-cloning check (counter must be strictly increasing if authenticator uses counter)
        if ($signCount > 0 && $storedCounter > 0 && $signCount <= $storedCounter) {
            throw new Exception("Authenticator counter rollback detected. Possible cloned authenticator.");
        }

        // Verify cryptographic signature
        // Signed data = authenticatorData || sha256(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData = $authenticatorDataBinary . $clientDataHash;

        $keyResource = openssl_pkey_get_public($publicKeyPem);
        if (!$keyResource) {
            throw new Exception("Failed to load public key: " . openssl_error_string());
        }

        $verifyResult = openssl_verify($signedData, $signatureBinary, $keyResource, OPENSSL_ALGO_SHA256);

        if ($verifyResult !== 1) {
            throw new Exception("Cryptographic biometric signature verification failed ($verifyResult)");
        }

        return [
            'verified' => true,
            'userPresent' => $up,
            'userVerified' => $uv,
            'signCount' => $signCount
        ];
    }
}
