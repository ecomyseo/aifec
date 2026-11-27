<?php
/** AIFEC — evaluación aislada con el catálogo y las tarifas de la tienda seleccionada. */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Cache;
use Aifec\Kernel\Site;

final class CatalogueEvaluation
{
    public static function measure(App $source): array
    {
        $folder = rtrim(sys_get_temp_dir(), '\\/') . '/aifec-evaluation-' . bin2hex(random_bytes(12));
        if (!mkdir($folder, 0700)) { throw new \RuntimeException('Temporary directory unavailable'); }
        $local = null;
        try {
            $site = new Site('evaluation', $folder, [
                'locale' => $source->lang(), 'timezone' => $source->site()->timezone(), 'url' => 'https://evaluation.test',
                'secret' => bin2hex(random_bytes(32)), 'db' => ['driver' => 'sqlite', 'path' => $folder . '/store.sqlite', 'prefix' => 'af_'],
            ]);
            $modules = array_values(array_filter(['assistant','catalog','search','shipping','orders','cms','returns','reviews'], static function ($mod) use ($source): bool {
                return $source->modules()->isLoaded($mod);
            }));
            $local = new App($site, $source->root(), ['modules' => $modules, 'http' => new FakeHttpClient(), 'log_files' => false]);
            $local->boot();
            self::copy($source, $local);
            if ($local->has('search')) { $local->service('search')->reindexNow(); }
            return self::run($source, $local);
        } finally {
            $source->makeCurrent();
            unset($local);
            gc_collect_cycles();
            if (is_dir($folder) && dirname(realpath($folder)) === realpath(sys_get_temp_dir())) {
                Cache::removeDir($folder);
            }
        }
    }

    private static function copy(App $source, App $local): void
    {
        $src = $source->db();
        $db = $local->db();
        $tables = ['tax_classes','tax_rates','shipping_zones','zone_locations','carriers','carrier_rates','brands','categories','category_i18n',
            'families','attributes','attribute_i18n','attribute_values','attribute_value_i18n','family_attributes','products','product_i18n',
            'product_categories','product_attributes','variants','variant_values','product_relations','tags','product_tags','product_faqs','slugs','synonyms'];
        $db->transaction(static function () use ($tables, $db, $src): void {
            foreach ($tables as $table) {
                if (!$src->tableExists($table) || !$db->tableExists($table)) { continue; }
                $columns = array_values(array_intersect($db->columns($table), $src->columns($table)));
                $filter = $table === 'slugs' ? " WHERE type IN ('product','category','brand')" : '';
                $order = in_array('id', $columns, true) ? 'id' : implode(', ', $columns);
                $db->run('DELETE FROM {' . $table . '}');
                for ($offset = 0; ; $offset += 500) {
                    $rows = $src->select('SELECT * FROM {' . $table . '}' . $filter . ' ORDER BY ' . $order . ' LIMIT 500 OFFSET ' . $offset);
                    foreach ($rows as $row) { $db->insert($table, array_intersect_key($row, array_flip($columns))); }
                    if (count($rows) < 500) { break; }
                }
            }
        });
        foreach (['i18n.languages','shop.currency','tax.base_country','tax.prices_include','shipping.default_country','search.synonyms',
            'search.sector','assistant.featured_ids','assistant.hours','assistant.returns_text','returns.days','assistant.about'] as $key) {
            $value = $source->settings()->get($key);
            if ($value !== null) { $local->settings()->set($key, $value); }
        }
        $local->settings()->set('ai.enabled', false);
    }

    private static function run(App $source, App $local): array
    {
        $stats = ['products' => 0, 'phrases' => 0, 'relevant' => 0, 'prices_checked' => 0, 'price_errors' => 0,
            'shipping_quotes' => 0, 'shipping_errors' => 0, 'failures' => []];
        $catalog = $source->service('catalog');
        $sample = $catalog->listing(['lang' => $source->lang(), 'in_stock' => true, 'per_page' => 40]);
        $assistant = $local->service('assistant');
        $shipping = $source->has('shipping') && $local->has('shipping');
        $shop = new ShopTools($local);
        foreach ($sample['items'] as $product) {
            $id = (int) $product['id'];
            ++$stats['products'];
            foreach (['Busco %s', '¿Me enseñas %s?', 'Do you have %s?', 'Where can I find %s?'] as $pattern) {
                $conv = $assistant->conversations()->create('es');
                $r = $assistant->reply($conv['id'], sprintf($pattern, $product['name']));
                ++$stats['phrases'];
                if (in_array($id, array_column($r['cards'], 'id'), true)) { ++$stats['relevant']; }
                elseif (count($stats['failures']) < 20) { $stats['failures'][] = ['id' => $id, 'query' => sprintf($pattern, $product['name'])]; }
                foreach ($r['cards'] as $item) {
                    $oracle = $catalog->card((int) $item['id'], $source->lang());
                    ++$stats['prices_checked'];
                    if (!$oracle || (int) $oracle['price']['amount'] !== $item['price_cents']) { ++$stats['price_errors']; }
                }
            }
            $item = $catalog->purchasable($id, 0, $source->lang());
            if (!$shipping || !$item || $item['kind'] !== 'physical') { continue; }
            foreach (['28001','35001','51001'] as $postcode) {
                $region = \Aifec\Geo\Spain::provinceByPostcode($postcode);
                $dest = ['country' => 'ES','postcode' => $postcode,'region' => $region['prefix'] ?? '', 'city' => ''];
                $rates = $source->service('shipping')->options(['weight' => (int) $item['weight'], 'items_total' => (int) $item['unit_price'], 'qty' => 1, 'needs_shipping' => true], $dest);
                $quote = $shop->shippingQuote(['product_id' => $id, 'postcode' => $postcode,'country' => 'ES'], []);
                ++$stats['shipping_quotes'];
                if (array_column($rates, 'price') !== array_column($quote['options'] ?? [], 'price_cents')) { ++$stats['shipping_errors']; }
            }
        }
        $stats['passed'] = $stats['phrases'] > 0 && $stats['relevant'] >= 0.9 * $stats['phrases'] && $stats['prices_checked'] > 0
            && $stats['price_errors'] === 0 && $stats['shipping_errors'] === 0;
        return $stats;
    }
}
