<?php
/**
 * AIFEC — ayudas de prueba de la seguridad. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 * Hace de navegador con una passkey de verdad: genera una clave P-256, monta el objeto de atestación en CBOR y firma las
 * afirmaciones, de modo que WebAuthn se comprueba con criptografía real y no con datos de mentira.
 *
 *   FakePasskey::create(): FakePasskey
 *   $key->registration(string $challenge, string $origin, string $rp): array       lo que manda el navegador al crear
 *   $key->assertion(string $challenge, string $origin, string $rp, int $count): array   lo que manda al entrar
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Security\WebAuthn;

if (!class_exists('FakePasskey')) {
    final class FakePasskey
    {
        /** @var resource */
        public $key;

        /** @var string */
        public $credentialId;

        /** @var string */
        public $x = '';

        /** @var string */
        public $y = '';

        public static function create(): self
        {
            $self = new self();
            $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
            $self->key = openssl_pkey_new($options);
            if ($self->key === false) {
                // PHP de Windows sin OPENSSL_CONF: se usa el openssl.cnf que viene con él.
                $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
                $self->key = is_file($cnf) ? openssl_pkey_new($options + ['config' => $cnf]) : false;
            }
            if ($self->key === false) {
                throw new \RuntimeException('openssl cannot make an EC key here: ' . (string) openssl_error_string());
            }
            $details = openssl_pkey_get_details($self->key);
            $self->x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $self->y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $self->credentialId = random_bytes(32);

            return $self;
        }

        public function id(): string
        {
            return WebAuthn::b64url($this->credentialId);
        }

        /** @return array<string,mixed> */
        public function registration(string $challenge, string $origin, string $rp, int $flags = 0x45, int $alg = -7): array
        {
            $cose = self::map([[self::int(1), self::int(2)], [self::int(3), self::int($alg)], [self::int(-1), self::int(1)], [self::int(-2), self::bytes($this->x)], [self::int(-3), self::bytes($this->y)]]);
            $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $cose;
            $att = self::map([[self::text('fmt'), self::text('none')], [self::text('attStmt'), self::map([])], [self::text('authData'), self::bytes($auth)]]);
            $client = json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);

            return ['id' => $this->id(), 'response' => ['clientDataJSON' => WebAuthn::b64url($client), 'attestationObject' => WebAuthn::b64url($att)]];
        }

        /** @return array<string,mixed> */
        public function assertion(string $challenge, string $origin, string $rp, int $count = 1, int $flags = 0x05, string $type = 'webauthn.get'): array
        {
            $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', $count);
            $client = json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
            openssl_sign($auth . hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

            return ['id' => $this->id(), 'response' => [
                'clientDataJSON' => WebAuthn::b64url($client), 'authenticatorData' => WebAuthn::b64url($auth), 'signature' => WebAuthn::b64url($signature),
            ]];
        }

        // ---- un CBOR mínimo para montar las pruebas

        public static function int(int $v): string
        {
            return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
        }

        public static function bytes(string $v): string
        {
            return self::head(2, strlen($v)) . $v;
        }

        public static function text(string $v): string
        {
            return self::head(3, strlen($v)) . $v;
        }

        /** @param array<int,array{0:string,1:string}> $pairs clave y valor ya codificados */
        public static function map(array $pairs): string
        {
            $out = self::head(5, count($pairs));
            foreach ($pairs as $pair) {
                $out .= $pair[0] . $pair[1];
            }

            return $out;
        }

        private static function head(int $major, int $n): string
        {
            if ($n < 24) {
                return chr(($major << 5) | $n);
            }
            if ($n < 256) {
                return chr(($major << 5) | 24) . chr($n);
            }

            return chr(($major << 5) | 25) . pack('n', $n);
        }
    }
}

if (!function_exists('securityApp')) {
    /** @param array<string,mixed> $opciones */
    function securityApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['security', 'jobs'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);
        $app = testApp($opciones);
        $app->boot();

        return $app;
    }
}
