<?php
/**
 * AIFEC — apoyo de las pruebas del correo: aplicación con transporte en memoria y un transporte que falla.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Mail\ArrayTransport;
use Aifec\Mail\MailException;
use Aifec\Mail\MimeMessage;
use Aifec\Mail\Transport;
use Aifec\Modules\Mail\Mailer;

/** Transporte que siempre falla, con el texto que se le diga (por ejemplo, con la contraseña dentro). */
final class MailTestFailingTransport implements Transport
{
    /** @var string */
    public $message;

    /** @var int */
    public $calls = 0;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function send(MimeMessage $message): void
    {
        ++$this->calls;
        throw new MailException($this->message);
    }
}

/**
 * Aplicación con el módulo de correo y un ArrayTransport en lugar de la red.
 *
 * @param array<string,mixed> $settings
 *
 * @return array{0:App,1:ArrayTransport}
 */
function mailTestApp(array $settings = [], ?Transport $transport = null): array
{
    $app = testApp(['modules' => ['mail'], 'settings' => $settings + ['mail.from_email' => 'tienda@tienda.test']]);
    $array = new ArrayTransport();
    $use = $transport !== null ? $transport : $array;
    $app->bind('mail.transport', static function () use ($use): Transport {
        return $use;
    });
    // Una plantilla que NO es del sistema (las del módulo lo son), para probar lo que el modo «hold» retiene.
    $app->hooks()->filter('mail.templates', static function (array $templates): array {
        $templates['plain_test'] = ['subject' => 'Hello {name} from {shop}', 'view' => 'mail::emails/mail_test', 'module' => 'tests', 'system' => false];
        $templates['broken_test'] = ['subject' => 'Broken', 'view' => 'mail::emails/does_not_exist', 'module' => 'tests', 'system' => false];

        return $templates;
    });
    $app->boot();

    return [$app, $array];
}

function mailTestService(App $app): Mailer
{
    $service = $app->service('mailer');
    check($service instanceof Mailer, 'el servicio mailer es el del módulo');

    return $service;
}

/** @return array<string,mixed> */
function mailTestRow(App $app, int $id): array
{
    $row = $app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
    check($row !== null, 'la fila ' . $id . ' existe');

    return (array) $row;
}

function mailTestCount(App $app): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}');
}
