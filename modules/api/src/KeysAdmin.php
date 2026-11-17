<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * El recurso «API keys» del panel: lo que hace falta además de lo que da el recurso genérico. La clave se genera al crear (con el
 * prefijo aifec_api_), se guarda solo su resumen y se enseña UNA vez, en un aviso de la pantalla siguiente.
 *
 * La tabla api_keys la comparten otros módulos: las claves del ERP (ámbitos «erp:…») no se tocan desde aquí.
 */
final class KeysAdmin
{
    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>|string
     */
    public static function beforeSave(array $values, ?array $current, App $app)
    {
        if ($current !== null) {
            if (self::foreign($current)) {
                return t('This key belongs to another module: manage it there');
            }
            // El resumen de la clave y la fecha no se cambian nunca, vengan lo que vengan en el formulario.
            $values['key_hash'] = (string) $current['key_hash'];
            $values['created_at'] = (string) $current['created_at'];

            return $values;
        }
        $key = Keys::generate();
        $values['key_hash'] = Keys::hash($key);
        $values['created_at'] = $app->clock()->sql();
        $app->request()->setAttr('api.new_key', $key);

        return $values;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $key = $app->request()->attr('api.new_key');
        if ($current === null && is_string($key) && $key !== '') {
            Admin::flash($app, 'warn', t('Copy this key now, it will not be shown again: {key}', ['key' => $key]));
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        return self::foreign($row) ? t('This key belongs to another module: manage it there') : null;
    }

    /**
     * La celda «Scopes» del listado.
     *
     * @param array<string,mixed> $row
     */
    public static function scopesCell(array $row, App $app): string
    {
        $scopes = Keys::scopes(isset($row['scopes']) ? $row['scopes'] : null);
        if ($scopes === []) {
            return '<span class="af-muted">' . e(t('Reading and carts only')) . '</span>';
        }
        $out = [];
        foreach ($scopes as $scope) {
            $out[] = '<span class="af-badge af-badge-info">' . e($scope) . '</span>';
        }

        return implode(' ', $out);
    }

    /**
     * ¿Es la clave de otro módulo (el ERP)?
     *
     * @param array<string,mixed> $row
     */
    private static function foreign(array $row): bool
    {
        foreach (Keys::scopes(isset($row['scopes']) ? $row['scopes'] : null) as $scope) {
            if (strpos($scope, 'erp:') === 0) {
                return true;
            }
        }

        return false;
    }
}
