<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;

/**
 * Qué productos llevan licencia y cómo: tipo (con dominios, o firmada con NIF), dominios permitidos y meses de actualizaciones.
 *
 * Es el dato tipado del producto: se enseña como «dato extra» (filtro product.extra) y se edita en la pestaña «Licences» de la ficha
 * del producto en el panel. La tabla entera (pequeña) se guarda en la caché con la etiqueta «gm-licencias».
 */
final class Products
{
    public const KINDS = ['none' => 'No licence', 'domain' => 'Key with domains', 'signed' => 'Signed key for a tax ID (works offline)'];

    /**
     * @return array<int,array{kind:string,max_domains:int,update_months:int}>
     */
    public static function all(App $app): array
    {
        try {
            $map = $app->cache()->remember('gm_lic.products', 300, static function () use ($app): array {
                $out = [];
                foreach ($app->db()->select('SELECT product_id, kind, max_domains, update_months FROM {gm_license_products}') as $row) {
                    $out[(int) $row['product_id']] = ['kind' => (string) $row['kind'], 'max_domains' => (int) $row['max_domains'], 'update_months' => (int) $row['update_months']];
                }

                return $out;
            }, ['gm-licencias']);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($map) ? $map : [];
    }

    /**
     * La licencia de un producto, o null si no lleva.
     *
     * @return array{kind:string,max_domains:int,update_months:int}|null
     */
    public static function get(App $app, int $productId): ?array
    {
        $all = self::all($app);

        return isset($all[$productId]) && $all[$productId]['kind'] !== 'none' ? $all[$productId] : null;
    }

    public static function set(App $app, int $productId, string $kind, int $maxDomains, int $months): void
    {
        if ($productId < 1) {
            return;
        }
        if ($kind === 'none' || !isset(self::KINDS[$kind])) {
            $app->db()->delete('gm_license_products', ['product_id' => $productId]);
        } else {
            $app->db()->upsert('gm_license_products', [
                'product_id' => $productId, 'kind' => $kind, 'max_domains' => max(0, min(1000, $maxDomains)), 'update_months' => max(0, min(240, $months)),
                'updated_at' => $app->clock()->sql(),
            ], ['product_id']);
        }
        $app->invalidate(['gm-licencias', 'product:' . $productId]);
    }

    /** Una frase corta para la ficha («3 domains, 12 months of updates»). */
    public static function describe(array $config): string
    {
        if ($config['kind'] === 'signed') {
            $what = t('Signed key for your tax ID, works offline');
        } else {
            $what = $config['max_domains'] > 0 ? tn('{n} domain', '{n} domains', $config['max_domains']) : t('Unlimited domains');
        }
        $months = $config['update_months'] > 0 ? tn('{n} month of updates', '{n} months of updates', $config['update_months']) : t('Updates with no end date');

        return $what . ' · ' . $months;
    }
}
