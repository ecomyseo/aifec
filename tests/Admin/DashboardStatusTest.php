<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\DashboardController;
use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

/*
 * El inicio que dice qué hacer: avisos por gravedad, cada uno en una frase llana con su botón, los que faltaban en el núcleo
 * (tareas paradas, correo retenido o sin configurar, sin copia reciente, tienda oculta, cobro en pruebas, PHP sin soporte), un
 * máximo de seis a la vista y, sin avisos, «Todo en orden». Cada aviso se provoca con su causa y desaparece al arreglarla.
 */

if (!class_exists('DashTestPayment')) {
    /** Una forma de pago de mentira, en pruebas o no. */
    final class DashTestPayment implements PaymentMethod
    {
        /** @var bool */
        public $test;

        /** @var bool */
        public $offered = true;

        /** @var string */
        public $title;

        public function __construct(bool $test, string $title = 'Tarjeta & Bizum')
        {
            $this->test = $test;
            $this->title = $title;
        }

        public function code(): string
        {
            return 'dash-pay';
        }

        public function info(): array
        {
            return ['title' => $this->title, 'description' => '', 'icon' => ''];
        }

        public function available(array $context): bool
        {
            return $this->offered;
        }

        public function fee(array $context): int
        {
            return 0;
        }

        public function isTest(): bool
        {
            return $this->test;
        }

        public function start(array $order): PaymentAction
        {
            return PaymentAction::paid('X-1');
        }

        public function supportsRefund(): bool
        {
            return false;
        }

        public function refund(array $order, int $amount, string $reason): string
        {
            return 'RF';
        }
    }
}

/**
 * Tienda con esos módulos, con la versión de PHP fijada (la del servidor que ejecuta las pruebas no importa) y una persona dentro.
 * $php es la versión que dirá el gancho; se cambia cambiando $state['php'].
 *
 * @param string[]            $modules
 * @param array<string,mixed> $settings
 * @param array<string,string> $state
 *
 * @return array{0:App,1:TestClient}
 */
function dashShop(array $modules, array $settings = [], array &$state = []): array
{
    $state += ['php' => '8.4.1'];
    $app = testApp(['modules' => $modules, 'settings' => $settings]);
    $app->boot();
    $app->hooks()->filter('admin.php_version', static function () use (&$state): string {
        return $state['php'];
    });

    return [$app, adminClient($app)];
}

/**
 * Los avisos que enseña el inicio: kind, text, href y label del botón, en el orden en que salen, y cuántos van fuera del plegado.
 *
 * @return array{visible:array<int,array<string,string>>,folded:array<int,array<string,string>>,summary:string}
 */
function dashNotices(string $html): array
{
    $parse = static function (string $chunk): array {
        $out = [];
        preg_match_all('#<div class="af-alert af-alert-(\w+)" role="status"><span>(.*?)</span></div>#s', $chunk, $m, PREG_SET_ORDER);
        foreach ($m as $alert) {
            if ($alert[1] === 'ok' && $alert[2] === 'Todo en orden') {
                continue;
            }
            $button = ['href' => '', 'label' => ''];
            $text = $alert[2];
            if (preg_match('#^(.*?) <a class="af-btn af-btn-sm" href="([^"]*)">(.*?)</a>$#s', $alert[2], $b) === 1) {
                $text = $b[1];
                $button = ['href' => html_entity_decode($b[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'label' => html_entity_decode($b[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            }
            $out[] = ['kind' => $alert[1], 'text' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')] + $button;
        }

        return $out;
    };
    $start = strpos($html, '<main');
    $body = $start !== false ? substr($html, $start) : $html;
    $parts = explode('<details class="af-help" data-af-status-more>', $body, 2);
    $summary = '';
    $folded = [];
    if (isset($parts[1])) {
        preg_match('#<summary>(.*?)</summary>#s', $parts[1], $s);
        $summary = isset($s[1]) ? $s[1] : '';
        $folded = $parse(explode('</details>', $parts[1], 2)[0]);
    }

    return ['visible' => $parse($parts[0]), 'folded' => $folded, 'summary' => $summary];
}

/** Los textos de los avisos que se ven. @return string[] */
function dashTexts(string $html): array
{
    $n = dashNotices($html);

    return array_map(static function (array $item): string {
        return $item['text'];
    }, array_merge($n['visible'], $n['folded']));
}

test('sin nada que avisar, una sola línea «Todo en orden»', function (): void {
    list($app, $web) = dashShop([]);
    $r = $web->get('/admin');
    same(200, $r->status);
    $n = dashNotices($r->body);
    same([], $n['visible']);
    same([], $n['folded']);
    contains('<div class="af-alert af-alert-ok" role="status"><span>Todo en orden</span></div>', $r->body);
    same(1, substr_count((string) substr($r->body, (int) strpos($r->body, '<main')), 'role="status"'), 'una sola línea, no una lista');
    notContains('data-af-status-more', $r->body, 'sin plegado');
});

test('PHP sin soporte: avisa con su botón y desaparece con una versión con soporte', function (): void {
    $state = ['php' => '7.4.33'];
    list($app, $web) = dashShop([], [], $state);
    $n = dashNotices($web->get('/admin')->body);
    same(1, count($n['visible']));
    same('warn', $n['visible'][0]['kind']);
    same('PHP 7.4 ya no recibe actualizaciones de seguridad', $n['visible'][0]['text']);
    same('https://www.php.net/supported-versions.php', $n['visible'][0]['href']);
    same('Ver las versiones con soporte', $n['visible'][0]['label']);

    foreach (['8.0.30' => true, '8.1.27' => true, '8.2.12' => false, '8.3.0' => false, '8.4.1' => false, '9.0.0' => false, 'raro' => false] as $version => $unsupported) {
        $state['php'] = (string) $version;
        same($unsupported ? 1 : 0, count(dashNotices($web->get('/admin')->body)['visible']), 'PHP ' . $version);
    }
    $now = testNow();
    check(DashboardController::phpUnsupported('8.2.0', (int) strtotime('2026-12-30 23:59:59 UTC')) === false, 'la 8.2 recibe seguridad hasta el 31 de diciembre de 2026');
    check(DashboardController::phpUnsupported('8.2.0', (int) strtotime('2026-12-31 00:00:00 UTC')) === true, 'y el 31 deja de recibirla');
    check(DashboardController::phpUnsupported('7.3.0', $now) === true, 'una anterior a la 7.4 también cuenta');
    check(DashboardController::phpUnsupported('5.6.40', $now) === true);
    check(DashboardController::phpUnsupported('8.9.0', $now) === false, 'una versión que no conocemos no se da por muerta');
    check(DashboardController::phpUnsupported('', $now) === false);
});

test('tareas programadas paradas: nunca, hace horas, y desaparece cuando vuelven a correr', function (): void {
    list($app, $web) = dashShop(['jobs']);
    $n = dashNotices($web->get('/admin')->body);
    same(1, count($n['visible']), 'un solo aviso, no el del módulo y el del núcleo');
    same('Las tareas programadas no se han ejecutado nunca', $n['visible'][0]['text']);
    same('Ver cómo activarlas', $n['visible'][0]['label']);
    same('/admin/jobs/schedule', $n['visible'][0]['href']);
    notContains($app->i18n()->t('Background tasks are not running'), $web->last->body, 'el aviso del módulo se sustituye por el del núcleo');

    $beat = static function (App $app, int $secondsAgo): void {
        $app->db()->run('DELETE FROM {cron_runs} WHERE task = ?', ['_heartbeat']);
        $app->db()->insert('cron_runs', ['task' => '_heartbeat', 'last_run_at' => $app->clock()->sql($app->clock()->now() - $secondsAgo), 'runs' => 5]);
    };
    $beat($app, 3 * 3600 + 120);
    same(['Las tareas programadas no se ejecutan desde hace 3 horas'], dashTexts($web->get('/admin')->body));
    $beat($app, 20 * 60);
    same(['Las tareas programadas no se ejecutan desde hace 20 minutos'], dashTexts($web->get('/admin')->body));
    $beat($app, 3 * 86400);
    same(['Las tareas programadas no se ejecutan desde hace 3 días'], dashTexts($web->get('/admin')->body));
    $beat($app, 60);
    same([], dashTexts($web->get('/admin')->body), 'corriendo, sin aviso');
    contains('Todo en orden', $web->last->body);
});

test('correo retenido o sin configurar: cada causa con su aviso, y se arregla cambiando el ajuste', function (): void {
    list($app, $web) = dashShop(['mail']);
    $n = dashNotices($web->get('/admin')->body);
    same(['Los correos están retenidos: los clientes no reciben nada'], dashTexts($web->last->body));
    same('/admin/settings/mail', $n['visible'][0]['href']);
    same('Abrir los ajustes', $n['visible'][0]['label']);
    notContains($app->i18n()->t('Email is on hold: nothing is being sent'), $web->last->body, 'sin el aviso repetido del módulo');

    $app->settings()->setMany(['mail.mode' => 'live', 'mail.transport' => 'smtp', 'mail.smtp_host' => '']);
    $n = dashNotices($web->get('/admin')->body);
    same(['Los correos no se pueden enviar: falta el servidor de correo'], dashTexts($web->last->body), 'SMTP sin servidor');
    same('bad', $n['visible'][0]['kind'], 'es de los graves');

    $app->settings()->setMany(['mail.transport' => 'mail', 'store.email' => '', 'mail.from_email' => '']);
    same(['Los correos salen sin remitente propio: pon el correo de la tienda'], dashTexts($web->get('/admin')->body));

    $app->settings()->setMany(['store.email' => 'tienda@ejemplo.test']);
    same([], dashTexts($web->get('/admin')->body), 'con remitente y envío en vivo, en orden');

    $app->settings()->setMany(['mail.transport' => 'smtp', 'mail.smtp_host' => 'smtp.ejemplo.test']);
    same([], dashTexts($web->get('/admin')->body), 'con servidor SMTP puesto, también');
    $app->settings()->setMany(['mail.mode' => 'redirect']);
    same([], dashTexts($web->get('/admin')->body), 'redirigido a una dirección de pruebas no es «retenido»');
});

test('copia de seguridad: ninguna, vieja y reciente', function (): void {
    list($app, $web) = dashShop(['tools']);
    $n = dashNotices($web->get('/admin')->body);
    same(['Todavía no hay ninguna copia de seguridad de la tienda'], dashTexts($web->last->body));
    same('/admin/tools/backups', $n['visible'][0]['href']);
    same('Abrir las copias de seguridad', $n['visible'][0]['label']);

    $dir = $app->service('backups')->dir();
    // El aviso solo cuenta las copias comprobadas: una copia sin comprobar es como no tener ninguna.
    $bk = new Aifec\Modules\Tools\Backups($app);
    file_put_contents($dir . '/backup-20261110-120000-01.sql', '-- copia de mentira');
    same(['Hay 1 copia de seguridad de la tienda sin comprobar: compruébala'], dashTexts($web->get('/admin')->body), 'sin comprobar no cuenta como copia, y el aviso dice que está ahí');
    $bk->markVerified('backup-20261110-120000-01.sql');
    same([], dashTexts($web->get('/admin')->body), 'una copia de hace dos días vale');
    // La sesión del panel caduca con el reloj parado de las pruebas, así que la copia se «envejece» cambiando el fichero.
    unlink($dir . '/backup-20261110-120000-01.sql');
    file_put_contents($dir . '/backup-20261108-120000-01.sql', '-- vieja');
    $bk->markVerified('backup-20261108-120000-01.sql');
    same(['La última copia de seguridad tiene 4 días'], dashTexts($web->get('/admin')->body));
    file_put_contents($dir . '/backup-20261112-110000-01.sql', '-- otra');
    same(['La última copia de seguridad tiene 4 días'], dashTexts($web->get('/admin')->body), 'una copia nueva sin comprobar no quita el aviso');
    $bk->markVerified('backup-20261112-110000-01.sql');
    same([], dashTexts($web->get('/admin')->body), 'con una copia nueva y comprobada, desaparece');
});

test('tienda oculta a los buscadores: aviso grave con botón para quien puede cambiarlo, y sin botón para el resto', function (): void {
    list($app, $web) = dashShop(['seo'], ['seo.noindex_site' => true]);
    $n = dashNotices($web->get('/admin')->body);
    same(['La tienda está oculta a los buscadores: nadie la encuentra en Google'], dashTexts($web->last->body));
    same('bad', $n['visible'][0]['kind']);
    same('/admin/settings/seo', $n['visible'][0]['href']);
    same('Cambiar', $n['visible'][0]['label']);
    notContains($app->i18n()->t('The whole site is hidden from search engines. Turn it off when the shop is ready.'), $web->last->body);

    $app->db()->insert('roles', ['code' => 'soloinicio', 'name' => 'Solo inicio', 'caps' => json_encode(['dashboard.view']), 'system' => 0]);
    $other = adminClient($app, 'soloinicio');
    $n = dashNotices($other->get('/admin')->body);
    same(1, count($n['visible']), 'quien solo ve el inicio lo sabe');
    same('', $n['visible'][0]['href'], 'pero sin botón: no puede cambiarlo');

    $app->settings()->set('seo.noindex_site', false);
    same([], dashTexts($web->get('/admin')->body));
});

test('un cobro en pruebas avisa mientras se ofrezca y desaparece al pasarlo a real o dejar de ofrecerlo', function (): void {
    list($app, $web) = dashShop(['orders']);
    $pay = new DashTestPayment(true);
    $app->service('payments')->register($pay);
    $n = dashNotices($web->get('/admin')->body);
    same(['El cobro «Tarjeta & Bizum» está en modo de pruebas: a nadie se le cobra de verdad'], dashTexts($web->last->body));
    same('/admin/settings', $n['visible'][0]['href']);
    contains('Tarjeta &amp; Bizum', $web->last->body, 'el nombre sale escapado');
    notContains('Tarjeta & Bizum', $web->last->body);

    $pay->offered = false;
    same([], dashTexts($web->get('/admin')->body), 'un cobro que no se ofrece no preocupa');
    $pay->offered = true;
    $pay->test = false;
    same([], dashTexts($web->get('/admin')->body), 'real, sin aviso');
});

test('los avisos salen por gravedad: primero los rojos, luego los amarillos, y a igualdad en su orden', function (): void {
    $items = [
        ['kind' => 'info', 'text' => 'i1', 'url' => '', 'label' => '', 'key' => ''],
        ['kind' => 'warn', 'text' => 'w1', 'url' => '', 'label' => '', 'key' => ''],
        ['kind' => 'bad', 'text' => 'b1', 'url' => '', 'label' => '', 'key' => ''],
        ['kind' => 'ok', 'text' => 'o1', 'url' => '', 'label' => '', 'key' => ''],
        ['kind' => 'warn', 'text' => 'w2', 'url' => '', 'label' => '', 'key' => ''],
        ['kind' => 'bad', 'text' => 'b2', 'url' => '', 'label' => '', 'key' => ''],
    ];
    $sorted = array_map(static function (array $i): string {
        return $i['text'];
    }, DashboardController::sort($items));
    same(['b1', 'b2', 'w1', 'w2', 'i1', 'o1'], $sorted);

    $state = ['php' => '7.4.33'];
    list($app, $web) = dashShop(['seo', 'mail', 'jobs'], ['seo.noindex_site' => true], $state);
    $kinds = array_map(static function (array $i): string {
        return $i['kind'];
    }, dashNotices($web->get('/admin')->body)['visible']);
    same(4, count($kinds), 'tareas, correo, PHP y tienda oculta');
    same(['bad', 'warn', 'warn', 'warn'], $kinds, 'el rojo, primero');
    $texts = dashTexts($web->last->body);
    same('La tienda está oculta a los buscadores: nadie la encuentra en Google', $texts[0]);
});

test('lo que traen los módulos se limpia: sin texto no hay aviso, un tipo raro es información, el marcado va escapado y el botón es opcional', function (): void {
    list($app, $web) = dashShop([]);
    $app->hooks()->filter('admin.status', static function ($status): array {
        $status = is_array($status) ? $status : [];
        $status[] = ['kind' => 'warn', 'text' => '   '];
        $status[] = 'no soy un aviso';
        $status[] = ['kind' => 'rojísimo', 'text' => 'Tipo <raro> & más'];
        $status[] = ['kind' => 'bad', 'text' => 'Con botón', 'url' => '/admin/algo?a=1&b=2', 'label' => 'Ir a «algo»'];
        $status[] = ['kind' => 'warn', 'text' => 'Con enlace sin etiqueta', 'url' => '/admin/otro'];

        return $status;
    });
    $n = dashNotices($web->get('/admin')->body);
    same(['Con botón', 'Con enlace sin etiqueta', 'Tipo <raro> & más'], dashTexts($web->last->body), 'por gravedad; el vacío y lo que no es un aviso se descartan');
    same('info', $n['visible'][2]['kind'], 'un tipo desconocido es solo información');
    same('/admin/algo?a=1&b=2', $n['visible'][0]['href']);
    same('Ir a «algo»', $n['visible'][0]['label']);
    same('Revisar', $n['visible'][1]['label'], 'un enlace sin etiqueta dice «Revisar»');
    same('', $n['visible'][2]['href'], 'sin dirección no hay botón');
    notContains('<raro>', $web->last->body);
    contains('Tipo &lt;raro&gt; &amp; más', $web->last->body);
});

test('como mucho seis avisos a la vista; el resto, plegado y con su cuenta', function (): void {
    list($app, $web) = dashShop([]);
    $app->hooks()->filter('admin.status', static function ($status): array {
        $status = is_array($status) ? $status : [];
        for ($i = 1; $i <= 8; $i++) {
            $status[] = ['kind' => $i <= 2 ? 'warn' : 'info', 'text' => 'Aviso ' . $i];
        }
        $status[] = ['kind' => 'bad', 'text' => 'Grave', 'url' => '/admin/grave', 'label' => 'Arreglar'];

        return $status;
    });
    $n = dashNotices($web->get('/admin')->body);
    same(6, count($n['visible']), 'seis a la vista');
    same(3, count($n['folded']), 'y tres plegados');
    same('3 avisos más', $n['summary']);
    same('Grave', $n['visible'][0]['text'], 'los más graves, a la vista');
    same('Aviso 1', $n['visible'][1]['text']);
    same(['Aviso 5', 'Aviso 6', 'Aviso 7', 'Aviso 8'], array_slice(array_map(static function (array $i): string {
        return $i['text'];
    }, array_merge($n['visible'], $n['folded'])), 5), 'y los últimos, por orden de llegada');
    notContains('Todo en orden', $web->last->body);

    // Con exactamente seis no hay plegado.
    $app2 = testApp();
    $app2->boot();
    $app2->hooks()->filter('admin.php_version', static function (): string {
        return '8.4.0';
    });
    $app2->hooks()->filter('admin.status', static function ($status): array {
        $status = is_array($status) ? $status : [];
        for ($i = 1; $i <= 6; $i++) {
            $status[] = ['kind' => 'warn', 'text' => 'Seis ' . $i];
        }

        return $status;
    });
    $web2 = adminClient($app2);
    $n = dashNotices($web2->get('/admin')->body);
    same(6, count($n['visible']));
    notContains('data-af-status-more', $web2->last->body);
});

test('cada aviso del núcleo es una frase llana con su botón, y quien no puede arreglarlo no lo ve', function (): void {
    $state = ['php' => '7.4.33'];
    list($app, $web) = dashShop(['jobs', 'mail', 'tools', 'seo', 'orders'], ['seo.noindex_site' => true], $state);
    $app->service('payments')->register(new DashTestPayment(true));
    $core = DashboardController::coreNotices($app);
    same(['tasks', 'mail_hold', 'backup', 'noindex', 'test_payment', 'php'], array_map(static function (array $i): string {
        return $i['key'];
    }, $core), 'los seis avisos del núcleo');
    foreach ($core as $item) {
        check($item['text'] !== '' && mb_strlen($item['text'], 'UTF-8') <= 110, $item['key'] . ': una frase corta');
        check(strpos($item['text'], "\n") === false && substr($item['text'], -1) !== '.', $item['key'] . ': sin punto final ni saltos');
        check($item['url'] !== '' && $item['label'] !== '', $item['key'] . ': con su botón');
        check(in_array($item['kind'], ['bad', 'warn'], true), $item['key'] . ': grave o aviso');
        notContains('{', $item['text'], $item['key'] . ': ningún marcador sin sustituir');
        check(preg_match('/^[A-Z¿¡]/u', $item['text']) === 1, $item['key'] . ' empieza con mayúscula');
    }
    // Nada en inglés en castellano.
    foreach (dashTexts($web->get('/admin')->body) as $text) {
        notContains('are on hold', $text);
        notContains('security updates', $text);
        notContains('Scheduled tasks', $text);
    }

    $app->db()->insert('roles', ['code' => 'soloinicio', 'name' => 'Solo inicio', 'caps' => json_encode(['dashboard.view']), 'system' => 0]);
    $other = adminClient($app, 'soloinicio');
    $texts = dashTexts($other->get('/admin')->body);
    same(['La tienda está oculta a los buscadores: nadie la encuentra en Google'], $texts, 'sin permisos solo ve lo que no necesita permiso');
});

test('un fallo al comprobar un cobro no tumba el inicio', function (): void {
    list($app, $web) = dashShop(['orders']);
    $app->service('payments')->register(new class implements PaymentMethod {
        public function code(): string
        {
            return 'rota';
        }

        public function info(): array
        {
            throw new \RuntimeException('no sé mi título');
        }

        public function available(array $context): bool
        {
            return true;
        }

        public function fee(array $context): int
        {
            return 0;
        }

        public function isTest(): bool
        {
            return true;
        }

        public function start(array $order): PaymentAction
        {
            return PaymentAction::paid('X');
        }

        public function supportsRefund(): bool
        {
            return false;
        }

        public function refund(array $order, int $amount, string $reason): string
        {
            return '';
        }
    });
    $r = $web->get('/admin');
    same(200, $r->status);
    same([], dashTexts($r->body), 'se apunta en el registro y el inicio sale igual');
});
