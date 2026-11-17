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

use Aifec\Kernel\App;

/**
 * Los pintores de los bloques dinámicos que usa `BlockRenderer`: imagen de la biblioteca, productos, producto, entradas y
 * formulario. Cada uno devuelve HTML propio del sistema y NUNCA lanza: si algo falta (no hay catálogo, no hay medios, el tema
 * no tiene la tarjeta) el bloque no pinta nada y la página sigue.
 */
final class Painters
{
    /** @var App */
    private $app;

    /** @var Cms */
    private $cms;

    public function __construct(App $app, Cms $cms)
    {
        $this->app = $app;
        $this->cms = $cms;
    }

    /**
     * @return array<string,callable>
     */
    public function all(): array
    {
        return [
            'image' => [$this, 'image'],
            'products' => [$this, 'products'],
            'product' => [$this, 'product'],
            'posts' => [$this, 'posts'],
            'form' => [$this, 'form'],
            'company' => [$this, 'company'],
        ];
    }

    /**
     * Los datos de la empresa de «Ajustes → General» (razón social o nombre, NIF, dirección, correo y teléfono), leídos al
     * pintar: el aviso legal, la privacidad y las condiciones de TODAS las tiendas dicen siempre lo que pone en Ajustes, y lo
     * que está vacío no sale (nada de «…» a la vista). Con `contact`, además, el enlace a la página del formulario de contacto.
     *
     * @param array<string,mixed> $attrs
     */
    public function company(array $attrs): string
    {
        $settings = $this->app->settings();
        $name = trim($settings->str('store.name', ''));
        $legal = trim($settings->str('store.legal_name', ''));
        $rows = [];
        $rows[] = [t('Owner'), e($legal !== '' ? $legal : $name)];
        if ($legal !== '' && $name !== '' && mb_strtolower($legal) !== mb_strtolower($name)) {
            $rows[] = [t('Trading name'), e($name)];
        }
        $vat = trim($settings->str('store.vat_id', ''));
        if ($vat !== '') {
            $rows[] = [t('Tax ID'), e($vat)];
        }
        $address = trim($settings->str('store.address', ''));
        if ($address !== '') {
            $rows[] = [t('Address'), implode('<br>', array_map('e', array_filter(array_map('trim', preg_split('/\R/u', $address) ?: []), 'strlen')))];
        }
        $email = trim($settings->str('store.email', ''));
        if ($email !== '') {
            $rows[] = [t('Email'), '<a href="mailto:' . e($email) . '">' . e($email) . '</a>'];
        }
        $phone = trim($settings->str('store.phone', ''));
        if ($phone !== '') {
            $rows[] = [t('Phone'), '<a href="tel:' . e((string) preg_replace('/[^0-9+]/', '', $phone)) . '">' . e($phone) . '</a>'];
        }
        if (!empty($attrs['contact'])) {
            $url = $this->contactPage();
            if ($url !== '') {
                $rows[] = [t('Contact form'), '<a href="' . e($url) . '">' . e(t('Write to us')) . '</a>'];
            }
        }
        if ($rows[0][1] === '') {
            array_shift($rows);
        }
        if ($rows === []) {
            return '';
        }
        $html = '<dl class="af-company">';
        foreach ($rows as $row) {
            $html .= '<dt>' . e($row[0]) . '</dt><dd>' . $row[1] . '</dd>';
        }
        $html .= '</dl>';
        if ($this->app->site()->isDemo()) {
            // En una demo los datos son de ejemplo: que nadie los tome por un comercio de verdad.
            $html = '<p class="af-company-note">' . e(t('This is a demo shop: nothing is charged or shipped, and these company details are sample data.')) . '</p>' . $html;
        }

        return $html;
    }

    /** La dirección de la primera página publicada que lleva un formulario (la de contacto), o '' si no hay. */
    private function contactPage(): string
    {
        try {
            $lang = $this->app->lang();
            $id = $this->app->db()->value(
                "SELECT p.id FROM {pages} p JOIN {page_i18n} x ON x.page_id = p.id AND x.lang = ? WHERE p.status = 'published' AND x.blocks LIKE ? ORDER BY p.position, p.id",
                [$lang, '%"type":"form"%']
            );

            return $id !== null ? $this->app->url()->entity('page', (int) $id, $lang) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * La etiqueta <img> de una imagen de la biblioteca (con sus anchos alternativos). Vacío si no existe.
     *
     * @param array<string,mixed> $attrs
     */
    public function image(array $attrs): string
    {
        try {
            $media = isset($attrs['media_id']) ? (int) $attrs['media_id'] : 0;
            if ($media <= 0 || !$this->app->has('media')) {
                return '';
            }
            $alt = isset($attrs['alt']) && is_string($attrs['alt']) && $attrs['alt'] !== '' ? $attrs['alt'] : null;
            $image = $this->app->service('media')->image($media, $alt);
            if ($image === null) {
                return '';
            }
            $size = isset($attrs['width']) && (int) $attrs['width'] > 0 && (int) $attrs['width'] <= 480 ? 'card' : 'large';

            return image_tag($image, $size, ['sizes' => '(max-width: 800px) 100vw, 800px']);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Una cuadrícula de tarjetas de producto: a mano (ids), de una categoría, las últimas, las destacadas o las rebajadas.
     *
     * @param array<string,mixed> $attrs
     */
    public function products(array $attrs): string
    {
        try {
            if (!$this->app->has('catalog')) {
                return '';
            }
            $catalog = $this->app->service('catalog');
            $mode = isset($attrs['mode']) ? (string) $attrs['mode'] : 'latest';
            $limit = isset($attrs['limit']) ? max(1, min(100, (int) $attrs['limit'])) : 4;
            $columns = isset($attrs['columns']) ? max(1, min(6, (int) $attrs['columns'])) : 4;
            $cards = [];
            if ($mode === 'manual') {
                $ids = [];
                foreach (isset($attrs['ids']) && is_array($attrs['ids']) ? $attrs['ids'] : [] as $id) {
                    if ((int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }
                $cards = array_values($catalog->cards(array_slice($ids, 0, $limit)));
            } else {
                $query = ['per_page' => $limit, 'page' => 1, 'sort' => 'newest'];
                if ($mode === 'category') {
                    $query['category_id'] = isset($attrs['category_id']) ? (int) $attrs['category_id'] : 0;
                } elseif ($mode === 'featured') {
                    $query['featured'] = true;
                } elseif ($mode === 'sale') {
                    $query['on_sale'] = true;
                }
                $result = $catalog->listing($query);
                $cards = isset($result['items']) && is_array($result['items']) ? array_values($result['items']) : [];
            }

            return $this->grid($cards, $columns);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Una tarjeta de producto.
     *
     * @param array<string,mixed> $attrs
     */
    public function product(array $attrs): string
    {
        try {
            if (!$this->app->has('catalog')) {
                return '';
            }
            $card = $this->app->service('catalog')->card(isset($attrs['id']) ? (int) $attrs['id'] : 0);

            return $card === null ? '' : $this->grid([$card], 1);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Las últimas entradas del blog (o de una categoría), con imagen, título, extracto y fecha.
     *
     * @param array<string,mixed> $attrs
     */
    public function posts(array $attrs): string
    {
        try {
            $query = ['per_page' => isset($attrs['limit']) ? max(1, min(100, (int) $attrs['limit'])) : 3, 'page' => 1];
            if (isset($attrs['category_id']) && (int) $attrs['category_id'] > 0) {
                $query['term_id'] = (int) $attrs['category_id'];
            }
            $result = $this->cms->posts($query);
            if ($result['items'] === []) {
                return '';
            }
            $html = '<div class="af-grid af-cols-3">';
            foreach ($result['items'] as $post) {
                $date = '';
                if (!empty($post['published_at'])) {
                    $time = \Aifec\Kernel\Clock::parse((string) $post['published_at']);
                    $date = '<time datetime="' . e(gmdate('Y-m-d', $time)) . '">' . e($this->app->i18n()->date($time, 'medium', $this->app->site()->timezone())) . '</time>';
                }
                $html .= '<article class="af-post-card">'
                    . '<a class="af-post-card-link" href="' . e((string) $post['url']) . '">'
                    . (is_array($post['image']) ? image_tag($post['image'], 'card') : '')
                    . '<h3>' . e((string) $post['title']) . '</h3></a>'
                    . ($post['excerpt'] !== '' ? '<p>' . e((string) $post['excerpt']) . '</p>' : '')
                    . $date . '</article>';
            }

            return $html . '</div>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * El formulario del comerciante.
     *
     * @param array<string,mixed> $attrs
     */
    public function form(array $attrs): string
    {
        try {
            return $this->cms->forms()->block($attrs);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $cards
     */
    private function grid(array $cards, int $columns): string
    {
        if ($cards === []) {
            return '';
        }
        $html = '<div class="af-grid af-cols-' . $columns . '">';
        foreach ($cards as $card) {
            $html .= $this->app->view()->render('partials/product-card', ['product' => $card]);
        }

        return $html . '</div>';
    }
}
