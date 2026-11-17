<?php
/**
 * AIFEC — inicio del panel (piezas que aporta cada módulo) y buscador de órdenes (Ctrl + K).
 *
 * Un módulo aporta piezas en su admin.php:
 *   'dashboard' => [['id' => 'sales.today', 'cap' => 'orders.view', 'size' => 3, 'order' => 10,
 *                    'render' => function (App $app): string { … }]]
 * size es el ancho en doceavos (3, 4, 6, 8 o 12). Si una pieza falla, se anota y las demás siguen.
 *
 * Avisos de estado: filtro «admin.status», lista de ['kind' => ok|warn|bad|info, 'text' => …, 'url' => …, 'label' => …].
 * Los avisos salen por gravedad, cada uno con su frase y su botón, con un máximo de seis a la vista (el resto, plegado) y «Todo en
 * orden» si no hay ninguno. El núcleo añade los suyos: tareas paradas, correo retenido o sin configurar, sin copia reciente,
 * tienda oculta a buscadores, cobro en pruebas y PHP sin soporte (el gancho «admin.php_version» deja fijar la versión en pruebas).
 * Resultados del buscador: filtro «admin.palette» (array $items, string $q) → ['label', 'group', 'url'].
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class DashboardController
{
    /** Cuántos avisos se ven; el resto va plegado. */
    public const MAX_VISIBLE = 6;

    /** Segundos sin que corran las tareas programadas para avisar. */
    private const TASKS_STALE = 900;

    /** Segundos sin copia de seguridad para avisar (tres días). */
    private const BACKUP_STALE = 259200;

    /** Gravedad de un aviso: menor = antes. */
    private const RANK = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];

    /** Fin del soporte de seguridad de cada versión de PHP (php.net/supported-versions). */
    private const PHP_SECURITY_END = ['7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31'];

    /** Avisos de módulos que el núcleo ya da con su propia frase: texto del módulo => clave. */
    private const REPLACED = [
        'Background tasks are not running' => 'tasks',
        'Email is on hold: nothing is being sent' => 'mail_hold',
        'The whole site is hidden from search engines. Turn it off when the shop is ready.' => 'noindex',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $p */
    public function index(Request $request, array $p): Response
    {
        $app = $this->app;
        $widgets = [];
        foreach ($app->adminDefinitions() as $module => $definition) {
            foreach (isset($definition['dashboard']) && is_array($definition['dashboard']) ? $definition['dashboard'] : [] as $widget) {
                if (!is_array($widget) || !isset($widget['render']) || (isset($widget['cap']) && !$app->auth()->can((string) $widget['cap']))) {
                    continue;
                }
                try {
                    $html = (string) call_user_func($widget['render'], $app);
                } catch (\Throwable $e) {
                    $app->log()->exception($e, 'dashboard:' . $module);
                    continue;
                }
                if (trim($html) === '') {
                    continue;
                }
                $size = isset($widget['size']) && in_array((int) $widget['size'], [3, 4, 6, 8, 9, 12], true) ? (int) $widget['size'] : 6;
                $widgets[] = ['order' => isset($widget['order']) ? (int) $widget['order'] : 100, 'size' => $size, 'html' => $html];
            }
        }
        usort($widgets, static function (array $a, array $b): int {
            return $a['order'] - $b['order'];
        });

        $status = [];
        if ($app->site()->isDemo()) {
            $status[] = ['kind' => 'info', 'text' => t('This is a demo shop: it is reset every night and nothing is charged or sent.')];
        }
        foreach ($app->modules()->failed() as $id => $reason) {
            $status[] = ['kind' => 'bad', 'text' => t('The module «{id}» could not be loaded.', ['id' => $id])];
        }
        $status = $app->hooks()->apply('admin.status', $status, $app);
        $notices = self::notices($app, is_array($status) ? $status : []);

        return Admin::page($app, 'core::admin/dashboard', [
            'widgets' => $widgets,
            'status' => array_slice($notices, 0, self::MAX_VISIBLE),
            'statusMore' => array_slice($notices, self::MAX_VISIBLE),
            'user' => $app->auth()->admin(),
        ], ['title' => t('Dashboard')]);
    }

    // ------------------------------------------------------------------ avisos

    /**
     * Los avisos del inicio, limpios y por gravedad: lo que aportan los módulos más lo que vigila el propio núcleo (tareas
     * paradas, correo, copia de seguridad, tienda oculta, cobro en pruebas y PHP sin soporte). Cuando el núcleo vigila lo mismo
     * que un módulo, queda el del núcleo, que va en una frase llana y con su botón.
     *
     * @param array<int,mixed> $fromModules
     *
     * @return array<int,array{kind:string,text:string,url:string,label:string,key:string}>
     */
    public static function notices(App $app, array $fromModules): array
    {
        $core = self::coreNotices($app);
        $replaced = [];
        foreach ($core as $item) {
            $replaced[$item['key']] = true;
        }
        // Los módulos mandan su texto ya traducido: se reconocen por la traducción de la frase en inglés con la que lo escriben.
        $known = [];
        foreach (self::REPLACED as $english => $topic) {
            $known[t($english)] = $topic;
        }
        $list = [];
        foreach ($fromModules as $item) {
            if (!is_array($item) || !isset($item['text']) || trim((string) $item['text']) === '') {
                continue;
            }
            $key = isset($item['key']) ? (string) $item['key'] : '';
            if ($key === '' && isset($known[(string) $item['text']])) {
                $key = $known[(string) $item['text']];
            }
            if ($key !== '' && isset($replaced[$key])) {
                continue;
            }
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $list[] = [
                'kind' => isset($item['kind']) && isset(self::RANK[(string) $item['kind']]) ? (string) $item['kind'] : 'info',
                'text' => trim((string) $item['text']),
                'url' => $url,
                'label' => isset($item['label']) && (string) $item['label'] !== '' ? (string) $item['label'] : ($url !== '' ? t('Review') : ''),
                'key' => $key,
            ];
        }

        return self::sort(array_merge($core, $list));
    }

    /**
     * Ordena por gravedad (rojo, amarillo, información, bien) y, a igualdad, por el orden en que llegaron.
     *
     * @param array<int,array{kind:string,text:string,url:string,label:string,key:string}> $items
     *
     * @return array<int,array{kind:string,text:string,url:string,label:string,key:string}>
     */
    public static function sort(array $items): array
    {
        $indexed = [];
        foreach (array_values($items) as $i => $item) {
            $indexed[] = [self::RANK[$item['kind']], $i, $item];
        }
        usort($indexed, static function (array $a, array $b): int {
            return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
        });

        return array_map(static function (array $row): array {
            return $row[2];
        }, $indexed);
    }

    /**
     * Lo que vigila el núcleo. Cada aviso solo sale a quien puede arreglarlo.
     *
     * @return array<int,array{kind:string,text:string,url:string,label:string,key:string}>
     */
    public static function coreNotices(App $app): array
    {
        $out = [];
        $auth = $app->auth();
        $now = $app->clock()->now();
        $make = static function (string $kind, string $key, string $text, string $url, string $label): array {
            return ['kind' => $kind, 'text' => $text, 'url' => $url, 'label' => $label, 'key' => $key];
        };

        // Tareas programadas paradas.
        if ($app->has('jobs') && $auth->can('jobs.view')) {
            $queue = $app->service('jobs');
            $last = is_object($queue) && method_exists($queue, 'lastRun') ? $queue->lastRun() : null;
            if ($last === null || $now - (int) $last > self::TASKS_STALE) {
                $text = $last === null
                    ? t('Scheduled tasks have never run')
                    : t('Scheduled tasks have not run for {time}', ['time' => self::age($now - (int) $last)]);
                $out[] = $make('warn', 'tasks', $text, $app->url()->plain('/admin/jobs/schedule'), t('See how to turn them on'));
            }
        }

        // Correo retenido o sin configurar.
        if ($app->has('mailer') && $auth->can('mail.edit')) {
            $settings = $app->settings();
            $mailUrl = $app->url()->plain('/admin/settings/mail');
            if (!in_array($settings->str('mail.mode', 'hold'), ['live', 'redirect'], true)) {
                $out[] = $make('warn', 'mail_hold', t('Emails are on hold, so customers receive nothing'), $mailUrl, t('Open the settings'));
            } elseif ($settings->str('mail.transport', 'mail') === 'smtp' && trim($settings->str('mail.smtp_host')) === '') {
                $out[] = $make('bad', 'mail_unset', t('Emails cannot be sent: the mail server is not set'), $mailUrl, t('Open the settings'));
            } elseif (trim($settings->str('mail.from_email')) === '' && trim($settings->str('store.email')) === '') {
                $out[] = $make('warn', 'mail_unset', t('Emails go out without a sender address: set the email of the shop'), $mailUrl, t('Open the settings'));
            }
        }

        // Sin copia de seguridad reciente.
        if ($app->has('backups') && $auth->can('tools.backup')) {
            $service = $app->service('backups');
            $all = is_object($service) && method_exists($service, 'all') ? $service->all() : [];
            $newest = 0;
            foreach (is_array($all) ? $all : [] as $backup) {
                $newest = max($newest, Clock::parse(is_array($backup) && isset($backup['created_at']) ? (string) $backup['created_at'] : ''));
            }
            $url = $app->url()->plain('/admin/tools/backups');
            $unchecked = $newest === 0 && is_object($service) && method_exists($service, 'unchecked') ? (int) $service->unchecked() : 0;
            if ($newest === 0 && $unchecked > 0) {
                $out[] = $make('warn', 'backup', tn('There is {n} backup of the shop that has not been checked: check it', 'There are {n} backups of the shop that have not been checked: check them', $unchecked), $url, t('Open the backups'));
            } elseif ($newest === 0) {
                $out[] = $make('warn', 'backup', t('There is no backup of the shop yet'), $url, t('Open the backups'));
            } elseif ($now - $newest > self::BACKUP_STALE) {
                $out[] = $make('warn', 'backup', t('The last backup is {time} old', ['time' => self::age($now - $newest)]), $url, t('Open the backups'));
            }
        }

        // Tienda oculta a los buscadores.
        if ($app->settings()->bool('seo.noindex_site', false) && !$app->site()->isDemo()) {
            $can = $auth->can('seo.edit');
            $out[] = $make('bad', 'noindex', t('The shop is hidden from search engines, so nobody finds it on Google'), $can ? $app->url()->plain('/admin/settings/seo') : '', $can ? t('Change') : '');
        }

        // Un cobro en modo de pruebas.
        if ($app->has('payments') && $auth->can('settings.edit') && !$app->site()->isDemo()) {
            $payments = $app->service('payments');
            $context = ['total' => 1000, 'currency' => $app->settings()->str('shop.currency', 'EUR'), 'country' => '', 'needs_shipping' => false, 'customer_id' => null, 'lang' => $app->defaultLang()];
            foreach (is_object($payments) && method_exists($payments, 'all') ? $payments->all() : [] as $method) {
                try {
                    if (!$method->isTest() || !$method->available($context)) {
                        continue;
                    }
                    $info = $method->info();
                    $name = isset($info['title']) ? (string) $info['title'] : $method->code();
                    $out[] = $make('warn', 'test_payment', t('The payment «{name}» is in test mode: nobody is really charged', ['name' => $name]), $app->url()->plain('/admin/settings'), t('Open the settings'));
                } catch (\Throwable $e) {
                    $app->log()->exception($e, 'dashboard.payment_test');
                }
            }
        }

        // Una versión de PHP que ya no recibe correcciones de seguridad.
        if ($auth->can('settings.edit')) {
            $version = $app->hooks()->apply('admin.php_version', PHP_VERSION);
            $version = is_string($version) ? $version : PHP_VERSION;
            if (self::phpUnsupported($version, $now)) {
                $short = implode('.', array_slice(explode('.', $version), 0, 2));
                $out[] = $make('warn', 'php', t('PHP {version} no longer gets security updates', ['version' => $short]), 'https://www.php.net/supported-versions.php', t('See the supported versions'));
            }
        }

        return $out;
    }

    /**
     * ¿Ya no recibe correcciones de seguridad esta versión de PHP? Se mira por la fecha en que dejó de recibirlas (la tabla es
     * la de la web de PHP); una versión que no está en la tabla y es anterior a la 7.4 también cuenta.
     */
    public static function phpUnsupported(string $version, int $now): bool
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }
        $minor = $m[1] . '.' . $m[2];
        if (isset(self::PHP_SECURITY_END[$minor])) {
            return $now >= Clock::parse(self::PHP_SECURITY_END[$minor] . ' 00:00:00');
        }

        return (int) $m[1] < 7 || ((int) $m[1] === 7 && (int) $m[2] < 4);
    }

    /** Una duración en palabras llanas: «20 minutos», «3 horas», «2 días». */
    private static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 7200) {
            return tn('{n} minute', '{n} minutes', max(1, (int) floor($seconds / 60)));
        }
        if ($seconds < 172800) {
            return tn('{n} hour', '{n} hours', (int) floor($seconds / 3600));
        }

        return tn('{n} day', '{n} days', (int) floor($seconds / 86400));
    }

    /** @param array<string,string> $p */
    public function palette(Request $request, array $p): Response
    {
        $q = mb_strtolower(trim($request->str('q')));
        $items = [];
        foreach (Menu::flat($this->app) as $item) {
            if ($q === '' || mb_strpos(mb_strtolower($item['label'] . ' ' . $item['group']), $q) !== false) {
                $items[] = $item;
            }
        }
        if ($q !== '') {
            $more = $this->app->hooks()->apply('admin.palette', [], $q, $this->app);
            foreach (is_array($more) ? $more : [] as $item) {
                if (is_array($item) && isset($item['label'], $item['url'])) {
                    $items[] = ['label' => (string) $item['label'], 'group' => isset($item['group']) ? (string) $item['group'] : '', 'url' => (string) $item['url']];
                }
            }
        }

        return Response::json(['items' => array_slice($items, 0, 40)]);
    }
}
