<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * La configuración de la tienda (un solo registro, id «main»): identidad, moneda, idiomas, impuestos, numeración,
 * correo, páginas legales y portada. Es un tipo de configuración: al repetirlo se actualiza sin mirar si se editó aquí.
 *
 * Lo que no se cambia aunque el origen lo diga: el modo del correo (sigue retenido), la moneda si ya hay pedidos en otra, el
 * idioma principal si el sitio ya tiene contenido y los precios «con impuestos» si ya hay productos propios. Una numeración
 * solo se adelanta, nunca se retrasa. La contraseña del correo va por App::setSecret().
 */
final class SettingsWriter extends WriterBase
{
    /** Símbolo de las monedas que el panel ofrece. */
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'MXN' => '$', 'COP' => '$', 'CLP' => '$', 'ARS' => '$'];

    public function type(): string
    {
        return 'settings';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $id = (string) $record['id'];
        $mapped = $ctx->mapped('settings', $id);
        $action = $this->decide('settings', $record, $ctx, $mapped !== null ? ['id' => $mapped['local_id']] : null);
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, 1);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, 1);
        }
        $this->identity($record);
        $this->currency($record, $ctx);
        $this->languages($record, $ctx);
        $this->taxes($record, $ctx);
        $this->numbering($record);
        $this->mail($record);
        $this->home($record);
        $this->images($record, $ctx);
        $this->leftovers($record, $ctx);
        $ctx->remember('settings', $id, 1, Neutral::hash($record));

        return $this->result($action, 1);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,mixed> $record
     */
    private function identity(array $record): void
    {
        $settings = $this->app->settings();
        $fields = ['shop_name' => ['store.name', 120], 'legal_name' => ['store.legal_name', 160], 'vat_id' => ['store.vat_id', 40],
            'email' => ['store.email', 191], 'phone' => ['store.phone', 40]];
        foreach ($fields as $field => $spec) {
            if (isset($record[$field]) && is_string($record[$field]) && trim($record[$field]) !== '') {
                $settings->set($spec[0], $this->cut($record[$field], $spec[1]));
            }
        }
        $address = isset($record['address']) && is_array($record['address']) ? $record['address'] : [];
        if ($address === []) {
            return;
        }
        $text = static function (string $key) use ($address): string {
            return isset($address[$key]) && is_string($address[$key]) ? trim($address[$key]) : '';
        };
        $lines = array_values(array_filter([$text('line1'), $text('line2'), trim($text('postcode') . ' ' . $text('city')), $text('country')], static function (string $line): bool {
            return $line !== '';
        }));
        if ($lines !== []) {
            $settings->set('store.address', $this->cut(implode("\n", $lines), 400));
        }
        if (preg_match('/^[A-Z]{2}$/D', $text('country')) === 1) {
            $settings->set('tax.base_country', $text('country'));
            if ($text('postcode') !== '') {
                $settings->set('tax.base_postcode', $this->cut($text('postcode'), 12));
            }
        }
    }

    /**
     * La moneda no se cambia si el sitio ya tiene pedidos en otra: los importes de esos pedidos perderían su sentido.
     *
     * @param array<string,mixed> $record
     */
    private function currency(array $record, WriteContext $ctx): void
    {
        $code = isset($record['currency']) && is_string($record['currency']) ? strtoupper(trim($record['currency'])) : '';
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return;
        }
        $settings = $this->app->settings();
        if ($code === $settings->str('shop.currency', 'EUR')) {
            return;
        }
        $db = $this->db();
        if ($db->tableExists('orders') && (int) $db->value('SELECT COUNT(*) FROM {orders} WHERE currency <> ?', [$code]) > 0) {
            $ctx->note('settings', 'main', 'note', 'The currency was not changed: the shop already has orders in another currency');

            return;
        }
        $settings->set('shop.currency', $code);
        $settings->set('shop.currency_symbol', isset(self::SYMBOLS[$code]) ? self::SYMBOLS[$code] : $code);
    }

    /**
     * Solo se AÑADEN idiomas, y solo los que AIFEC sabe traducir. El principal solo cambia en un sitio sin contenido.
     *
     * @param array<string,mixed> $record
     */
    private function languages(array $record, WriteContext $ctx): void
    {
        $wanted = [];
        foreach (isset($record['languages']) && is_array($record['languages']) ? $record['languages'] : [] as $lang) {
            if (is_string($lang) && $lang !== '') {
                $wanted[$this->shortLang($lang)] = true;
            }
        }
        $default = isset($record['default_language']) && is_string($record['default_language']) ? $this->shortLang($record['default_language']) : '';
        if ($default !== '') {
            $wanted[$default] = true;
        }
        if ($wanted === []) {
            return;
        }
        $settings = $this->app->settings();
        $current = [];
        foreach ($settings->arr('i18n.languages') as $lang) {
            if (is_string($lang) && preg_match('/^[a-z]{2}$/D', $lang) === 1 && !in_array($lang, $current, true)) {
                $current[] = $lang;
            }
        }
        if ($current === []) {
            $current = $this->app->languages();
        }
        $list = $current;
        $available = $this->availableLanguages();
        foreach (array_keys($wanted) as $lang) {
            if (in_array($lang, $list, true)) {
                continue;
            }
            if (in_array($lang, $available, true) && preg_match('/^[a-z]{2}$/D', (string) $lang) === 1) {
                $list[] = $lang;
            } else {
                $ctx->unmapped('language', (string) $lang, 1, 'AIFEC has no translation for this language: it was not added');
            }
        }
        if ($default !== '' && $list[0] !== $default && in_array($default, $list, true) && !$this->hasContent()) {
            $list = array_merge([$default], array_values(array_diff($list, [$default])));
        }
        if ($list !== $current) {
            $settings->set('i18n.languages', $list);
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function taxes(array $record, WriteContext $ctx): void
    {
        $settings = $this->app->settings();
        if (isset($record['display_prices_with_tax']) && is_bool($record['display_prices_with_tax'])) {
            $settings->set('tax.display_with_tax', $record['display_prices_with_tax']);
        }
        if (isset($record['prices_include_tax']) && is_bool($record['prices_include_tax'])) {
            $precise = $record['prices_include_tax'] === false && isset($record['extra']['net_price_precision']) && $record['extra']['net_price_precision'] === true;
            if ($record['prices_include_tax'] !== $settings->bool('tax.prices_include_tax', true) && $this->ownProducts() > 0) {
                $ctx->note('settings', 'main', 'note', 'The way prices are written was not changed: the shop already has products of its own');
            } elseif ($precise && $settings->bool('tax.prices_include_tax', true)) {
                // El origen guarda los precios sin impuestos con más de dos decimales: aquí se siguen escribiendo con impuestos y cada producto
                // cuesta lo mismo que allí (una base en céntimos no puede formar todos los importes con impuestos).
                $ctx->note('settings', 'main', 'note', 'The source writes prices without taxes with more than two decimals: here prices keep including taxes so that every product costs exactly the same');
            } else {
                $settings->set('tax.prices_include_tax', $record['prices_include_tax']);
            }
        }
        if (isset($record['tax_based_on']) && is_string($record['tax_based_on']) && $record['tax_based_on'] !== 'shipping') {
            $ctx->unmapped('setting', 'tax_based_on', 1, 'Taxes here are always worked out from the shipping address');
        }
        if (isset($record['guest_checkout']) && is_bool($record['guest_checkout'])) {
            $settings->set('orders.guest_checkout', $record['guest_checkout']);
        }
    }

    /**
     * El siguiente número de aquí tiene que ser mayor que el último del origen: se adelanta el contador, nunca se retrasa.
     *
     * @param array<string,mixed> $record
     */
    private function numbering(array $record): void
    {
        $db = $this->db();
        if (!$db->tableExists('sequences')) {
            return;
        }
        $series = trim($this->app->settings()->str('invoices.series', 'F'));
        foreach (['order' => 'order_number_next', 'invoice:' . ($series !== '' ? $series : 'F') => 'invoice_number_next'] as $name => $field) {
            $next = isset($record[$field]) && is_int($record[$field]) ? $record[$field] : 0;
            if ($next <= 1) {
                continue;
            }
            $db->insertIgnore('sequences', ['name' => $name, 'value' => 0]);
            $db->run('UPDATE {sequences} SET value = ? WHERE name = ? AND value < ?', [$next - 1, $name, $next - 1]);
        }
    }

    /**
     * El correo: remitente y transporte. La contraseña va cifrada y el modo no se toca (sigue retenido).
     *
     * @param array<string,mixed> $record
     */
    private function mail(array $record): void
    {
        $mail = isset($record['mail']) && is_array($record['mail']) ? $record['mail'] : [];
        if ($mail === []) {
            return;
        }
        $settings = $this->app->settings();
        $text = static function (string $key) use ($mail): string {
            return isset($mail[$key]) && is_string($mail[$key]) ? trim($mail[$key]) : '';
        };
        if ($text('transport') !== '') {
            $settings->set('mail.transport', $text('transport') === 'smtp' ? 'smtp' : 'mail');
        }
        $map = ['host' => 'mail.smtp_host', 'username' => 'mail.smtp_user', 'from_email' => 'mail.from_email', 'from_name' => 'mail.from_name'];
        foreach ($map as $field => $key) {
            if ($text($field) !== '') {
                $settings->set($key, $this->cut($text($field), 190));
            }
        }
        if (isset($mail['port']) && is_int($mail['port']) && $mail['port'] > 0 && $mail['port'] <= 65535) {
            $settings->set('mail.smtp_port', $mail['port']);
        }
        if (in_array($text('encryption'), ['none', 'ssl', 'tls'], true)) {
            $settings->set('mail.smtp_encryption', $text('encryption'));
        }
        if ($text('password') !== '') {
            $this->secrets[] = $text('password');
            $this->app->setSecret('mail.smtp_password', $text('password'));
        }
    }

    /**
     * Título y descripción de la portada (si el módulo de posicionamiento está) y las páginas legales (para el escritor de páginas).
     *
     * @param array<string,mixed> $record
     */
    private function home(array $record): void
    {
        $settings = $this->app->settings();
        if ($this->app->modules()->isLoaded('seo')) {
            $title = isset($record['home_title']) && is_array($record['home_title']) ? $this->firstText($record['home_title']) : '';
            $description = isset($record['home_description']) && is_array($record['home_description']) ? $this->firstText($record['home_description']) : '';
            if ($title !== '') {
                $settings->set('seo.title.home', $this->cut($title, 200));
            }
            if ($description !== '') {
                $settings->set('seo.description.home', $this->cut($description, 400));
            }
        }
        $legal = [];
        foreach (isset($record['legal_pages']) && is_array($record['legal_pages']) ? $record['legal_pages'] : [] as $kind => $sourceId) {
            if (is_string($kind) && is_scalar($sourceId) && (string) $sourceId !== '') {
                $legal[$kind] = (string) $sourceId;
            }
        }
        if ($legal !== []) {
            $settings->set('import.legal_pages', $legal);
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function images(array $record, WriteContext $ctx): void
    {
        foreach (['logo' => 'store.logo', 'icon' => 'store.icon'] as $field => $key) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $id = $ctx->image($record[$field], 'settings', 'main');
                if ($id !== null) {
                    $this->app->settings()->set($key, $id);
                }
            }
        }
    }

    /**
     * Lo que no tiene ajuste propio se guarda junto, sin secretos, para consultarlo.
     *
     * @param array<string,mixed> $record
     */
    private function leftovers(array $record, WriteContext $ctx): void
    {
        $clean = Neutral::redact('settings', $record);
        $keep = [];
        foreach (['timezone', 'weight_unit', 'dimension_unit', 'stock_management', 'tax_based_on', 'order_number_prefix', 'invoice_number_prefix'] as $field) {
            if (isset($clean[$field])) {
                $keep[$field] = $clean[$field];
            }
        }
        if (isset($clean['extra']) && is_array($clean['extra']) && $clean['extra'] !== []) {
            $keep['extra'] = $clean['extra'];
        }
        if ($keep !== []) {
            $this->app->settings()->set('import.source_settings', $keep);
        }
    }

    /**
     * @param array<mixed,mixed> $texts
     */
    private function firstText(array $texts): string
    {
        $main = $this->app->defaultLang();
        if (isset($texts[$main]) && is_string($texts[$main]) && trim($texts[$main]) !== '') {
            return trim($texts[$main]);
        }
        foreach ($texts as $text) {
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return '';
    }

    /**
     * Idiomas que AIFEC sabe traducir: el inglés (la lengua de las cadenas) y cada catálogo de core/lang.
     *
     * @return string[]
     */
    private function availableLanguages(): array
    {
        $out = ['en'];
        $files = glob($this->app->root() . '/core/lang/*.php');
        foreach (is_array($files) ? $files : [] as $file) {
            $name = basename((string) $file, '.php');
            if (preg_match('/^[a-z]{2}$/D', $name) === 1 && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** ¿El sitio ya tiene contenido propio (productos o páginas)? */
    private function hasContent(): bool
    {
        $db = $this->db();
        foreach (['products', 'pages'] as $table) {
            if ($db->tableExists($table) && (int) $db->value('SELECT COUNT(*) FROM {' . $table . '}') > 0) {
                return true;
            }
        }

        return false;
    }

    /** Productos que no vienen de una migración. */
    private function ownProducts(): int
    {
        $db = $this->db();
        if (!$db->tableExists('products')) {
            return 0;
        }

        return (int) $db->value("SELECT COUNT(*) FROM {products} WHERE id NOT IN (SELECT local_id FROM {import_map} WHERE type = 'products')");
    }
}
