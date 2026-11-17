<?php
/**
 * AIFEC — módulo de migración: publica el servicio «import», registra los lectores que trae y avisa en el inicio de
 * una migración parada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\ImportService;

return static function (App $app): void {
    $app->bind('import', static function (App $app): ImportService {
        return new ImportService($app);
    });

    // Los lectores de WooCommerce y de PrestaShop: se añaden los que existen (cada uno lo escribe otra parte del módulo).
    $app->hooks()->filter('import.readers', static function ($list): array {
        $list = is_array($list) ? $list : [];
        $classes = [
            'Aifec\\Modules\\Import\\Readers\\WooCommerce\\ApiReader',
            'Aifec\\Modules\\Import\\Readers\\WooCommerce\\PublicReader',
            'Aifec\\Modules\\Import\\Readers\\WooCommerce\\DbReader',
            'Aifec\\Modules\\Import\\Readers\\PrestaShop\\ApiReader',
            'Aifec\\Modules\\Import\\Readers\\PrestaShop\\DbReader',
        ];
        foreach ($classes as $class) {
            if (class_exists($class) && !in_array($class, $list, true)) {
                $list[] = $class;
            }
        }

        return $list;
    });

    // Estilos y guion de las pantallas de la migración: solo se cargan en ellas.
    $app->hooks()->slot('admin.head', static function () use ($app): string {
        if (strpos($app->request()->path, '/admin/import') !== 0) {
            return '';
        }

        return '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'import', 'import.css')) . '">';
    });
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        if (strpos($app->request()->path, '/admin/import') !== 0) {
            return '';
        }

        return '<script src="' . e($app->url()->asset('m', 'import', 'import.js')) . '" defer></script>';
    });

    // Aviso del inicio: una migración parada o que ha fallado necesita a alguien.
    $app->hooks()->filter('admin.status', static function ($status, App $app): array {
        $status = is_array($status) ? $status : [];
        if (!$app->auth()->can('import.run')) {
            return $status;
        }
        /** @var ImportService $service */
        $service = $app->service('import');
        $open = $service->runs()->open();
        if ($open !== null && $open['status'] === 'paused' && (int) $open['dry_run'] === 0) {
            $status[] = [
                'kind' => 'warn', 'text' => t('A migration is paused'),
                'url' => $app->url()->plain('/admin/import/' . (int) $open['id'] . '/progress'), 'label' => t('See why'),
            ];
        }

        return $status;
    });
};
