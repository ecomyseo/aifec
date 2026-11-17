<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Kernel\App;
use Aifec\Util\Str;

/**
 * Páginas legales de partida: Aviso legal, Privacidad, Cookies, Condiciones de compra y Envíos y devoluciones.
 *
 * Se crean como BORRADORES, con los datos de la tienda (`store.*`) dentro, en cada idioma del sitio (lo que no esté
 * traducido sale en inglés). Son un punto de partida: hay que revisarlas antes de publicarlas. Las que ya se crearon
 * (ajuste `cms.legal`: código → identificador de página) no se vuelven a crear mientras la página exista.
 *
 * Los textos se escriben en inglés y se traducen con el catálogo del módulo; `s()` solo marca la cadena.
 */
final class Legal
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Las páginas que se pueden crear: código → título (en inglés).
     *
     * @return array<string,string>
     */
    public static function pages(): array
    {
        return [
            'legal-notice' => 'Legal notice',
            'privacy' => 'Privacy policy',
            'cookies' => 'Cookie policy',
            'terms' => 'Terms and conditions of sale',
            'shipping-returns' => 'Shipping and returns',
        ];
    }

    /**
     * Estado de cada página: código → ['title', 'id' (0 si no existe), 'status'].
     *
     * @return array<string,array{title:string,id:int,status:string}>
     */
    public function status(): array
    {
        $db = $this->app->db();
        $map = $this->app->settings()->arr('cms.legal');
        $out = [];
        foreach (self::pages() as $code => $title) {
            $id = isset($map[$code]) ? (int) $map[$code] : 0;
            $status = $id > 0 ? $db->value('SELECT status FROM {pages} WHERE id = ?', [$id]) : null;
            $out[$code] = ['title' => t($title), 'id' => $status !== null ? $id : 0, 'status' => $status !== null ? (string) $status : ''];
        }

        return $out;
    }

    /**
     * Crea como borrador las páginas que faltan. Devuelve los códigos creados.
     *
     * @return string[]
     */
    public function create(): array
    {
        $created = [];
        $map = $this->app->settings()->arr('cms.legal');
        $before = $map;
        foreach (array_keys(self::pages()) as $code) {
            $id = isset($map[$code]) ? (int) $map[$code] : 0;
            if ($id > 0 && $this->app->db()->value('SELECT id FROM {pages} WHERE id = ?', [$id]) !== null) {
                continue;
            }
            $existing = $this->findExisting($code);
            if ($existing > 0) {
                $map[$code] = $existing;
                continue;
            }
            $map[$code] = $this->createOne($code);
            $created[] = $code;
        }
        if ($map !== $before) {
            $this->app->settings()->set('cms.legal', $map);
            $this->app->invalidate(['cms']);
        }

        return $created;
    }

    /** Reutiliza la dirección legal que ya existe sin sobrescribir su contenido ni su estado. */
    private function findExisting(string $code): int
    {
        $aliases = [
            'legal-notice' => ['aviso-legal', 'legal-notice'],
            'privacy' => ['privacidad', 'politica-de-privacidad', 'privacy', 'privacy-policy'],
            'cookies' => ['cookies', 'politica-de-cookies', 'cookie-policy'],
            'terms' => ['condiciones-de-compra', 'terms-and-conditions-of-sale'],
            'shipping-returns' => ['envios-y-devoluciones', 'envio-y-devoluciones', 'shipping-and-returns'],
        ];
        $lang = $this->app->defaultLang();
        $paths = isset($aliases[$code]) ? $aliases[$code] : [];
        $paths[] = Str::slug($this->app->i18n()->t(self::pages()[$code], [], $lang), 90);
        foreach ($this->app->db()->column('SELECT id FROM {pages} ORDER BY id') as $id) {
            if (in_array($this->app->slugs()->path('page', (int) $id, $lang), $paths, true)) {
                return (int) $id;
            }
        }

        return 0;
    }

    private function createOne(string $code): int
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $admin = $this->app->auth()->adminId();
        $id = $db->transaction(function () use ($db, $code, $now, $admin): int {
            $row = ['status' => 'draft', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'created_at' => $now, 'updated_at' => $now];
            if ($admin !== null) {
                $row['author_id'] = $admin;
            }
            $pageId = $db->insert('pages', $row);
            foreach ($this->app->languages() as $lang) {
                $title = $this->app->i18n()->t(self::pages()[$code], [], $lang);
                $blocks = BlockSchema::validate($this->blocksFor($code, $lang));
                $db->insert('page_i18n', [
                    'page_id' => $pageId,
                    'lang' => $lang,
                    'title' => $title,
                    'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'excerpt' => '',
                    'meta_title' => '',
                    'meta_description' => '',
                ]);
                $slug = Str::slug($title, 90);
                $this->app->slugs()->set('page', $pageId, $lang, $slug !== '' ? $slug : 'page-' . $pageId);
            }

            return $pageId;
        });
        (new Revisions($this->app))->record('page', $id);
        $this->app->hooks()->fire('content.saved', 'page', $id);

        return $id;
    }

    // ------------------------------------------------------------------ textos

    /**
     * Los bloques de una página legal en un idioma. Los datos de la empresa NO se copian en el texto: van en un bloque
     * «company» que lee «Ajustes → General» al pintar, así que el aviso legal, la privacidad y las condiciones de todas las
     * tiendas dicen siempre lo que pone en Ajustes (y lo que esté vacío no sale).
     *
     * @return array<int,array<string,mixed>> bloques sin validar
     */
    public function blocksFor(string $code, string $lang): array
    {
        $out = [];
        foreach ($this->outline($code) as $section) {
            $out[] = ['type' => 'heading', 'attrs' => ['level' => 2, 'html' => $this->app->i18n()->t($section[0], [], $lang)]];
            foreach ($section[1] as $paragraph) {
                if ($paragraph === self::COMPANY || $paragraph === self::COMPANY_CONTACT) {
                    $out[] = ['type' => 'company', 'attrs' => $paragraph === self::COMPANY_CONTACT ? ['contact' => true] : []];
                    continue;
                }
                $out[] = ['type' => 'paragraph', 'attrs' => ['html' => e($this->app->i18n()->t($paragraph, [], $lang))]];
            }
        }

        return $out;
    }

    /**
     * Vuelve a escribir, en todos los idiomas, las páginas legales que ya existen (las de `cms.legal`) con los textos de
     * ahora. Guarda una revisión de cada una antes, así que se puede volver atrás. Devuelve los códigos reescritos.
     *
     * @param string[]|null $codes solo estas (null = todas)
     *
     * @return string[]
     */
    public function refresh(?array $codes = null): array
    {
        $db = $this->app->db();
        $map = $this->app->settings()->arr('cms.legal');
        $done = [];
        foreach (array_keys(self::pages()) as $code) {
            if ($codes !== null && !in_array($code, $codes, true)) {
                continue;
            }
            $id = isset($map[$code]) ? (int) $map[$code] : 0;
            if ($id <= 0 || $db->value('SELECT id FROM {pages} WHERE id = ?', [$id]) === null) {
                continue;
            }
            $this->rewrite($id, $code);
            $done[] = $code;
        }
        if ($done !== []) {
            $this->app->invalidate(['cms']);
        }

        return $done;
    }

    /** Escribe en la página `$id` los bloques de la página legal `$code`, en cada idioma del sitio. */
    public function rewrite(int $id, string $code): void
    {
        $db = $this->app->db();
        (new Revisions($this->app))->record('page', $id);
        $db->transaction(function () use ($db, $id, $code): void {
            foreach ($this->app->languages() as $lang) {
                $blocks = json_encode(BlockSchema::validate($this->blocksFor($code, $lang)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($db->value('SELECT page_id FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, $lang]) !== null) {
                    $db->update('page_i18n', ['blocks' => $blocks], ['page_id' => $id, 'lang' => $lang]);
                    continue;
                }
                $title = $this->app->i18n()->t(self::pages()[$code], [], $lang);
                $db->insert('page_i18n', ['page_id' => $id, 'lang' => $lang, 'title' => $title, 'blocks' => $blocks, 'excerpt' => '', 'meta_title' => '', 'meta_description' => '']);
                $slug = Str::slug($title, 90);
                $this->app->slugs()->set('page', $id, $lang, $slug !== '' ? $slug : 'page-' . $id);
            }
            $db->update('pages', ['updated_at' => $this->app->clock()->sql()], ['id' => $id]);
        });
        (new Revisions($this->app))->record('page', $id);
        $this->app->hooks()->fire('content.saved', 'page', $id);
    }

    /** Apunta la página `$id` como la página legal `$code` (para el estado, el /cookies y que no se cree otra). */
    public function register(string $code, int $id): void
    {
        if (!isset(self::pages()[$code]) || $id <= 0) {
            return;
        }
        $map = $this->app->settings()->arr('cms.legal');
        if (isset($map[$code]) && (int) $map[$code] === $id) {
            return;
        }
        $map[$code] = $id;
        $this->app->settings()->set('cms.legal', $map);
    }

    /** Marcas del esquema: aquí va el bloque con los datos de la empresa (con o sin el enlace al formulario de contacto). */
    private const COMPANY = '@company';
    private const COMPANY_CONTACT = '@company+contact';

    /** Marca una cadena para el catálogo de traducciones (no hace nada más). */
    private static function s(string $text): string
    {
        return $text;
    }

    /**
     * @return array<int,array{0:string,1:string[]}> [título de la sección, párrafos]
     */
    private function outline(string $code): array
    {
        switch ($code) {
            case 'legal-notice':
                return [
                    [self::s('Owner of this website'), [self::s('This website belongs to:'), self::COMPANY_CONTACT]],
                    [self::s('Purpose'), [self::s('This website presents the shop and lets you buy its products online.')]],
                    [self::s('Conditions of use'), [self::s('Using this website means that you accept these conditions. You agree to use it lawfully and not to harm the rights or the interests of third parties.')]],
                    [self::s('Intellectual property'), [self::s('The texts, images, logos and design of this website belong to its owner or to their authors and may not be copied or distributed without permission.')]],
                    [self::s('Liability'), [self::s('The owner takes care to keep the information accurate and up to date, but does not guarantee that the website is free of errors or that it is always available.')]],
                    [self::s('Applicable law'), [self::s('These conditions are governed by the law of the country where the owner of this website is established.')]],
                ];
            case 'privacy':
                return [
                    [self::s('Who is responsible for your data'), [self::s('The controller of your personal data is:'), self::COMPANY_CONTACT]],
                    [self::s('Which data we collect'), [self::s('We collect the data that you give us when you create an account, place an order or write to us through a form: name, email, phone, delivery and billing addresses, and the content of your message.')]],
                    [self::s('Why we use them'), [self::s('We use your data to manage your orders, send you what you buy, answer your questions and comply with our legal obligations. We only send you commercial messages if you have agreed to receive them.')]],
                    [self::s('Legal basis'), [self::s('The execution of the purchase contract, our legal obligations and, in other cases, your consent.')]],
                    [self::s('Who receives your data'), [self::s('Only the providers that we need to serve you (payment, shipping, email and hosting) and the authorities when the law requires it.')]],
                    [self::s('How long we keep them'), [self::s('As long as the relationship lasts and, afterwards, during the legal periods of limitation.')]],
                    [self::s('Your rights'), [self::s('You can ask for access, rectification, erasure, restriction, portability or objection by writing to the email shown above. You can also complain to your data protection authority.')]],
                ];
            case 'cookies':
                return [
                    [self::s('What cookies are'), [self::s('Cookies are small files that a website stores in your browser to remember information about your visit.')]],
                    [self::s('Which cookies we use'), [self::s('This website uses the technical cookies that are needed to keep your cart and your session. Analytics and advertising cookies are only used if you accept them.')]],
                    [self::s('How to manage them'), [self::s('You can accept or reject the optional cookies when you visit the website for the first time and change your choice at any time. You can also delete cookies from the settings of your browser.')]],
                    [self::s('Contact'), [self::s('If you have questions about cookies, write to us:'), self::COMPANY_CONTACT]],
                ];
            case 'terms':
                return [
                    [self::s('Who sells'), [self::s('The seller is:'), self::COMPANY_CONTACT]],
                    [self::s('Orders'), [self::s('To place an order you add the products to the cart and follow the checkout. The contract is formed when we confirm your order by email.')]],
                    [self::s('Prices and payment'), [self::s('The prices shown are final prices unless stated otherwise. You can pay with the methods offered at checkout.')]],
                    [self::s('Delivery'), [self::s('We deliver to the address that you give us. The delivery time and the cost are shown before you pay.')]],
                    [self::s('Right of withdrawal'), [self::s('You have 14 days from the delivery to withdraw from the contract without giving a reason. To do so, write to the email shown under «Who sells».')]],
                    [self::s('Legal guarantee'), [self::s('The products have the legal guarantee that corresponds to them according to the law.')]],
                    [self::s('Complaints'), [self::s('You can send us your complaints through the contact details shown under «Who sells».')]],
                ];
            default:
                return [
                    [self::s('Shipping'), [self::s('You will receive an email with the tracking details when your order leaves our warehouse.')]],
                    [self::s('Shipping costs and times'), [self::s('The cost and the delivery time depend on the destination and on the carrier and are shown before you pay.')]],
                    [self::s('Returns'), [self::s('You can return a product within 14 days from the delivery. Write to us and we will tell you how to do it.')]],
                    [self::s('Refunds'), [self::s('Once we receive the product in good condition, we refund the amount with the same payment method that you used.')]],
                    [self::s('Contact'), [self::COMPANY_CONTACT]],
                ];
        }
    }
}
