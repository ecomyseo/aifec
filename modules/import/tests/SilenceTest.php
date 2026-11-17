<?php
/**
 * AIFEC — la migración no avisa a nadie: con el correo en modo real, un escritor que intenta mandar un correo por cada
 * registro no consigue ni uno, y el silencio se repone siempre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Mail\ArrayTransport;
use Aifec\Mail\Transport;

require_once __DIR__ . '/helpers.php';

/**
 * Sitio con el correo en modo real y un transporte en memoria.
 *
 * @return array{0:App,1:ArrayTransport}
 */
function importMailApp(): array
{
    $app = importApp(['import', 'media', 'catalog', 'mail'], ['mail.mode' => 'live', 'mail.from_email' => 'tienda@tienda.test']);
    $transport = new ArrayTransport();
    $app->bind('mail.transport', static function () use ($transport): Transport {
        return $transport;
    });
    $app->hooks()->filter('mail.templates', static function (array $templates): array {
        $templates['plain_test'] = ['subject' => 'Hello {name} from {shop}', 'view' => 'mail::emails/mail_test', 'module' => 'tests', 'system' => false];

        return $templates;
    });

    return [$app, $transport];
}

test('con el correo en modo real, un escritor que intenta mandar un correo por registro no consigue ni uno', function (): void {
    [$app, $transport] = importMailApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 5)];
    ImportFakeWriter::$mail = true;
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);

    same('done', importRun($app, $id)['status']);
    same(5, count(ImportFakeWriter::$mailLog), 'el escritor lo intentó en cada registro');
    same([0, 0, 0, 0, 0], ImportFakeWriter::$mailLog, 'y Mailer::queue() devolvió 0 todas las veces');
    same(0, count($transport->sent), 'no salió ningún mensaje');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'), 'ni se guardó ninguna fila');
    same([true, true, true, true, true], ImportFakeWriter::$silenced, 'dentro de la tanda la aplicación está en silencio');
    same(false, $app->silenced(), 'y al terminar vuelve a hablar');

    $mailId = $app->service('mailer')->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
    check($mailId > 0, 'fuera de la migración el correo funciona');
    same(1, count($transport->sent));
});

test('el silencio se repone también si el escritor lanza y si falla la migración entera', function (): void {
    [$app] = importMailApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    ImportFakeWriter::$mail = true;
    ImportFakeWriter::$throwOn = ['redirects:101' => true, 'redirects:102' => true, 'redirects:103' => true];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    $runner->step($id);
    same(false, $app->silenced(), 'tras una tanda cuyo escritor lanza');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'));

    ImportFakeReader::$failures = [new \LogicException('fallo inesperado')];
    $second = importDraft($app, ['types' => ['redirects']]);
    $runner->cancel($id);
    $runner->start($second, false);
    same('failed', $runner->step($second));
    same(false, $app->silenced(), 'tras un fallo que cierra la migración');
});

test('si la aplicación ya estaba en silencio, sigue en silencio al acabar la tanda', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    $app->silence(true);
    $runner->step($id);
    same(true, $app->silenced(), 'se repone el valor que había, no «falso»');
    $app->silence(false);
});

test('el simulacro tampoco avisa a nadie', function (): void {
    [$app, $transport] = importMailApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 4)];
    ImportFakeWriter::$mail = true;
    $id = importDraft($app, ['types' => ['redirects']], ['dry_run' => true]);
    importService($app)->runner()->start($id);
    importWork($app);
    same('done', importRun($app, $id)['status']);
    same(0, count($transport->sent));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'));
});
