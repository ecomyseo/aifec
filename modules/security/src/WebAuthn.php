<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

/**
 * WebAuthn de nivel 2, lo justo para entrar en el panel: claves ES256 (P-256), sin atestación, con verificación de usuario.
 * Sin dependencias: solo openssl. Cada fallo lanza InvalidArgumentException con la causa en inglés (va al registro, no a la pantalla).
 */
final class WebAuthn
{
    private const FLAG_UP = 0x01;
    private const FLAG_UV = 0x04;
    private const FLAG_AT = 0x40;

    /** Cabecera DER de una clave pública P-256 (SubjectPublicKeyInfo) hasta el punto sin comprimir. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function fromB64url(string $text): string
    {
        $text = strtr($text, '-_', '+/');
        $raw = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($raw === false) {
            throw new \InvalidArgumentException('Invalid base64url');
        }

        return $raw;
    }

    public static function challenge(): string
    {
        return self::b64url(random_bytes(32));
    }

    /**
     * Lo que da el navegador al crear una passkey → lo que hay que guardar.
     *
     * @param array<string,mixed> $in id, clientDataJSON y attestationObject, los tres en base64url
     *
     * @return array{credentialId:string,publicKey:string,signCount:int}
     */
    public static function verifyRegistration(array $in, string $challenge, string $origin, string $rpId): array
    {
        $clientRaw = self::fromB64url((string) ($in['clientDataJSON'] ?? ''));
        self::checkClientData($clientRaw, 'webauthn.create', $challenge, $origin);
        $att = Cbor::decode(self::fromB64url((string) ($in['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new \InvalidArgumentException('Attestation without authData');
        }
        $auth = $att['authData'];
        $flags = self::checkAuthData($auth, $rpId);
        if (($flags & self::FLAG_AT) === 0 || strlen($auth) < 55) {
            throw new \InvalidArgumentException('No credential data');
        }
        $idLength = (ord($auth[53]) << 8) | ord($auth[54]);
        if ($idLength < 16 || $idLength > 1023 || strlen($auth) < 55 + $idLength) {
            throw new \InvalidArgumentException('Invalid credential id');
        }
        $credentialId = substr($auth, 55, $idLength);
        $offset = 55 + $idLength;
        $cose = Cbor::decode($auth, $offset);
        if (!is_array($cose) || ($cose[1] ?? null) !== 2 || ($cose[3] ?? null) !== -7 || ($cose[-1] ?? null) !== 1
            || !isset($cose[-2], $cose[-3]) || !is_string($cose[-2]) || !is_string($cose[-3]) || strlen($cose[-2]) !== 32 || strlen($cose[-3]) !== 32) {
            throw new \InvalidArgumentException('Only ES256 keys are accepted');
        }
        $pem = self::pem("\x04" . $cose[-2] . $cose[-3]);
        $given = (string) ($in['id'] ?? '');
        if ($given !== '' && !hash_equals(self::b64url($credentialId), $given)) {
            throw new \InvalidArgumentException('The credential id does not match');
        }

        return ['credentialId' => self::b64url($credentialId), 'publicKey' => $pem, 'signCount' => self::counter($auth)];
    }

    /**
     * Lo que da el navegador al usar una passkey → el contador nuevo si la firma es buena.
     *
     * @param array<string,mixed> $in clientDataJSON, authenticatorData y signature, en base64url
     */
    public static function verifyAssertion(array $in, string $challenge, string $origin, string $rpId, string $publicKeyPem, int $storedCount): int
    {
        $clientRaw = self::fromB64url((string) ($in['clientDataJSON'] ?? ''));
        self::checkClientData($clientRaw, 'webauthn.get', $challenge, $origin);
        $auth = self::fromB64url((string) ($in['authenticatorData'] ?? ''));
        self::checkAuthData($auth, $rpId);
        $signature = self::fromB64url((string) ($in['signature'] ?? ''));
        if ($signature === '' || strlen($signature) > 200) {
            throw new \InvalidArgumentException('Invalid signature');
        }
        $ok = openssl_verify($auth . hash('sha256', $clientRaw, true), $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \InvalidArgumentException('The signature is not valid');
        }
        $count = self::counter($auth);
        // Un contador que no sube delata una clave copiada. Algunas llaves (iCloud, Google) siempre mandan cero: eso se acepta.
        if (($count !== 0 || $storedCount !== 0) && $count <= $storedCount) {
            throw new \InvalidArgumentException('The use counter did not increase');
        }

        return $count;
    }

    /** @param string $type webauthn.create o webauthn.get */
    private static function checkClientData(string $raw, string $type, string $challenge, string $origin): void
    {
        $client = json_decode($raw, true);
        if (!is_array($client) || ($client['type'] ?? '') !== $type) {
            throw new \InvalidArgumentException('Wrong client data type');
        }
        if (!is_string($client['challenge'] ?? null) || !hash_equals($challenge, $client['challenge'])) {
            throw new \InvalidArgumentException('The challenge does not match');
        }
        if (!is_string($client['origin'] ?? null) || !hash_equals($origin, $client['origin'])) {
            throw new \InvalidArgumentException('The origin does not match');
        }
        if (!empty($client['crossOrigin'])) {
            throw new \InvalidArgumentException('Cross-origin requests are refused');
        }
    }

    /** @return int los indicadores */
    private static function checkAuthData(string $auth, string $rpId): int
    {
        if (strlen($auth) < 37) {
            throw new \InvalidArgumentException('Authenticator data too short');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($auth, 0, 32))) {
            throw new \InvalidArgumentException('The relying party does not match');
        }
        $flags = ord($auth[32]);
        if (($flags & self::FLAG_UP) === 0 || ($flags & self::FLAG_UV) === 0) {
            throw new \InvalidArgumentException('The user was not present and verified');
        }

        return $flags;
    }

    private static function counter(string $auth): int
    {
        $n = unpack('N', substr($auth, 33, 4));

        return (int) $n[1];
    }

    private static function pem(string $point): string
    {
        $der = hex2bin(self::SPKI_PREFIX) . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
