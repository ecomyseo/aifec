<?php
/**
 * AIFEC — el enlace de restablecer la contraseña del panel lleva el dominio configurado, nunca el de la cabecera Host.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/** @return array{0:\Aifec\Kernel\App,1:\ArrayObject} */
function resetLinkApp(array $config): array
{
    $app = testApp(['config' => $config]);
    aifecAdmin($app);
    $sent = new \ArrayObject();
    $app->bind('mailer', function () use ($sent) {
        return new class($sent) {
            /** @var \ArrayObject */
            private $sent;

            public function __construct(\ArrayObject $sent)
            {
                $this->sent = $sent;
            }

            /**
             * @param array<string,mixed> $data
             * @param array<string,mixed> $options
             */
            public function queue(string $template, string $to, array $data = [], array $options = []): int
            {
                $this->sent[] = ['template' => $template, 'to' => $to, 'data' => $data, 'options' => $options];

                return count($this->sent);
            }
        };
    });

    return [$app, $sent];
}

test('el enlace de restablecer va al dominio de la tienda aunque la petición traiga otra cabecera Host', function (): void {
    [$app, $sent] = resetLinkApp([]);
    $web = new TestClient($app);
    $web->post('/admin/forgot', ['email' => 'ana@tienda.test'], ['host' => 'atacante.example']);
    same(1, count($sent));
    $url = (string) $sent[0]['data']['url'];
    check(strpos($url, 'https://tienda.test/admin/reset/') === 0, 'el enlace es de la tienda: ' . $url);
    check(strpos($url, 'atacante') === false);
});

test('sin dirección configurada del sitio no se manda ningún enlace de restablecer', function (): void {
    [$app, $sent] = resetLinkApp(['url' => '']);
    $web = new TestClient($app);
    $r = $web->post('/admin/forgot', ['email' => 'ana@tienda.test'], ['host' => 'atacante.example']);
    same(200, $r->status, 'la respuesta es la misma de siempre');
    same(0, count($sent), 'no se envía nada');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {tokens} WHERE kind = 'admin_reset'"), 'ni se crea la ficha');
});
