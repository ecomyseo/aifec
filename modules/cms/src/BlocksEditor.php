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
 * Lo que el panel necesita para el editor de bloques: sus recursos (hoja de estilos y guion), los textos ya traducidos, los
 * nombres de los tipos, las direcciones que usa (importar código de WordPress, vista previa, buscador de productos y selector de
 * imágenes) y la lista de formularios.
 *
 * `assets()` se llama desde el hueco `admin.foot`, que no sabe qué campos se han pintado: solo emite las etiquetas en las pantallas
 * donde puede haber un campo `blocks` (la ficha de un recurso del panel y las pantallas del propio módulo) o cuando quien pinta el
 * campo lo ha avisado con `painted()`. En cualquier otra pantalla no sale nada.
 */
final class BlocksEditor
{
    /** Marca de la petición que avisa de que se ha pintado un campo de bloques. */
    public const FLAG = 'cms.blocks_editor';

    /**
     * Las etiquetas del editor (hoja de estilos, textos en JSON y guion), o una cadena vacía si la pantalla no las necesita.
     */
    public static function assets(App $app): string
    {
        if (!self::wanted($app)) {
            return '';
        }
        $json = json_encode(self::config($app), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }
        $url = $app->url();

        return '<link rel="stylesheet" href="' . e($url->asset('m', 'cms', 'blocks-editor.css')) . '">'
            . '<script type="application/json" id="af-blocks-i18n">' . $json . '</script>'
            . '<script src="' . e($url->asset('m', 'cms', 'blocks-editor.js')) . '" defer></script>';
    }

    /**
     * Avisa de que en esta petición se ha pintado un campo de bloques: así se cargan los recursos aunque la dirección no lo diga.
     */
    public static function painted(App $app): void
    {
        $app->request()->setAttr(self::FLAG, true);
    }

    /**
     * ¿Hace falta cargar el editor en esta petición?
     */
    public static function wanted(App $app): bool
    {
        $request = $app->request();
        if ($request->attr(self::FLAG) === true) {
            return true;
        }

        return preg_match('#^/admin/(r|cms)/#', (string) $request->path) === 1;
    }

    /**
     * Todo lo que lee el guion: `t` (textos), `types` (nombres de los tipos de bloque), `urls` y `forms`.
     *
     * @return array<string,mixed>
     */
    public static function config(App $app): array
    {
        $url = $app->url();

        return [
            't' => self::texts(),
            'types' => self::typeNames(),
            'urls' => [
                'import' => $url->plain('/admin/cms/blocks/import'),
                'render' => $url->plain('/admin/cms/blocks/render'),
                'search' => $url->plain('/admin/r/products/search.json'),
                'picker' => $url->plain('/admin/media/picker'),
            ],
            'forms' => self::forms($app),
        ];
    }

    /**
     * Los formularios que se pueden poner en una página: [{id, name}].
     *
     * @return array<int,array<string,mixed>>
     */
    private static function forms(App $app): array
    {
        $out = [];
        try {
            $rows = $app->db()->select('SELECT id, name FROM {forms} ORDER BY name');
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => t((string) $row['name'])];
        }

        return $out;
    }

    /**
     * Nombre de cada tipo de bloque, para el menú y la barra de cada bloque.
     *
     * @return array<string,string>
     */
    private static function typeNames(): array
    {
        return [
            'paragraph' => t('Paragraph'),
            'heading' => t('Heading'),
            'list' => t('List'),
            'quote' => t('Quote'),
            'image' => t('Image'),
            'gallery' => t('Gallery'),
            'table' => t('Table'),
            'columns' => t('Columns'),
            'column' => t('Column'),
            'group' => t('Group'),
            'cover' => t('Cover'),
            'buttons' => t('Buttons'),
            'separator' => t('Separator'),
            'spacer' => t('Spacer'),
            'video' => t('Video'),
            'embed' => t('Embedded content'),
            'details' => t('Collapsible section'),
            'code' => t('Code'),
            'html' => t('HTML'),
            'products' => t('Products'),
            'product' => t('Featured product'),
            'posts' => t('Recent posts'),
            'form' => t('Form'),
            'toc' => t('Table of contents'),
            'company' => t('Company details'),
        ];
    }

    /**
     * Los textos del editor por su clave corta. Las claves son identificadores del guion; lo que se ve es el valor.
     *
     * @return array<string,string>
     */
    private static function texts(): array
    {
        return [
            // barra de herramientas y menú
            'add_block' => t('Add block'),
            'search_block' => t('Search blocks…'),
            'no_match' => t('No block matches'),
            'move_up' => t('Move up'),
            'move_down' => t('Move down'),
            'duplicate' => t('Duplicate'),
            'delete' => t('Delete'),
            'drag' => t('Drag to move'),
            'undo' => t('Undo'),
            'redo' => t('Redo'),
            'bold' => t('Bold'),
            'italic' => t('Italic'),
            'link' => t('Link'),
            'unlink' => t('Remove link'),
            'link_prompt' => t('Address of the link'),
            'preview' => t('Preview'),
            'cancel' => t('Cancel'),
            'empty' => t('This content has no blocks yet. Add the first one.'),
            'block_moved' => t('Block moved'),
            // código de WordPress
            'wp_import' => t('Paste WordPress blocks'),
            'wp_ph' => t('Paste the WordPress block code here'),
            'wp_do' => t('Convert to blocks'),
            'imported' => t('{n} blocks added, {r} to review'),
            'import_failed' => t('The code could not be converted'),
            'preview_failed' => t('The preview could not be loaded'),
            // textos y campos
            'p_ph' => t('Write something, or type / to add a block'),
            'h_ph' => t('Heading'),
            'quote_ph' => t('Quote'),
            'cite_ph' => t('Author or source'),
            'caption_ph' => t('Caption'),
            'summary_ph' => t('Title of the section'),
            'item_ph' => t('Item'),
            'code_ph' => t('Code'),
            'alt' => t('Alternative text'),
            'alt_ph' => t('Describe the image'),
            'href_label' => t('Link address'),
            'align' => t('Alignment'),
            'align_none' => t('Default'),
            'left' => t('Left'),
            'center' => t('Center'),
            'right' => t('Right'),
            'level' => t('Level'),
            'anchor' => t('Anchor'),
            'ordered' => t('Numbered list'),
            'item_add' => t('Add item'),
            'item_remove' => t('Remove item'),
            'remove' => t('Remove'),
            // imágenes
            'choose_image' => t('Choose image'),
            'add_images' => t('Add images'),
            'image_n' => t('Image {n}'),
            // tabla, columnas, grupo y portada
            'columns' => t('Columns'),
            'column_n' => t('Column {n}'),
            'header_row' => t('Header row'),
            'row_add' => t('Add row'),
            'row_remove' => t('Remove row'),
            'col_add' => t('Add column'),
            'col_remove' => t('Remove column'),
            'group_tag' => t('Element'),
            'width' => t('Width'),
            'width_normal' => t('Normal'),
            'wide' => t('Wide'),
            'full' => t('Full width'),
            'overlay' => t('Overlay color'),
            'dim' => t('Overlay strength (0 to 100)'),
            'min_height' => t('Minimum height (px)'),
            // botones, espacio, vídeo y código
            'button_text' => t('Button text'),
            'button_href' => t('Address'),
            'variant' => t('Style'),
            'fill' => t('Filled'),
            'outline' => t('Outline'),
            'target' => t('Opens in'),
            'same_tab' => t('The same tab'),
            'new_tab' => t('A new tab'),
            'button_add' => t('Add button'),
            'button_remove' => t('Remove button'),
            'height' => t('Height (px)'),
            'video_url' => t('Video address (https://)'),
            'poster_url' => t('Poster image address (https://)'),
            'embed_url' => t('Address of the content (https://)'),
            'https_only' => t('Only addresses that start with https:// are accepted.'),
            'language' => t('Language'),
            'html_notice' => t('The server cleans this HTML when you save: scripts and unsafe attributes are removed.'),
            'needs_review' => t('Needs review'),
            // tienda y entradas
            'mode' => t('What to show'),
            'm_manual' => t('Products chosen by hand'),
            'm_category' => t('Products of a category'),
            'm_latest' => t('Latest products'),
            'm_featured' => t('Featured products'),
            'm_sale' => t('Products on sale'),
            'count' => t('How many'),
            'category_id' => t('Category number'),
            'search_products' => t('Search products…'),
            'no_results' => t('No results'),
            'product_n' => t('Product {n}'),
            'form' => t('Form'),
            'form_pick' => t('Choose a form'),
            'max_level' => t('Deepest heading level'),
            'company_hint' => t('Shows the company details of Settings → General as they are when the page is shown.'),
            'company_contact' => t('Add the link to the contact form'),
        ];
    }
}
