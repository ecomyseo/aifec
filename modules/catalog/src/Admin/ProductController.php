<?php
/**
 * AIFEC — acciones de un producto que no son un recurso genérico: duplicar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class ProductController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Copia un producto como borrador y abre la copia.
     *
     * @param array<string,string> $params
     */
    public function duplicate(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.edit');
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $copy = ProductSaver::duplicate($this->app, $id);
        if ($copy === null) {
            throw HttpError::notFound();
        }
        $name = (string) $this->app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$copy, $this->app->defaultLang()]);
        Admin::audit($this->app, 'duplicate', 'products', (string) $copy, $name, ['from' => [(string) $id, (string) $copy]]);
        CategoryAdmin::recount($this->app);
        Admin::flash($this->app, 'ok', t('Product duplicated. This is the copy, still a draft.'));

        return Response::redirect(Admin::resourceUrl($this->app, 'products', $copy));
    }

    /**
     * «Rellenar con IA» en el alta: a partir del nombre propone una descripción corta y una categoría. Es una propuesta que devuelve
     * en JSON: no guarda nada, y la pantalla deja aceptar cada campo por separado.
     *
     * @param array<string,string> $params
     */
    public function quickfill(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('catalog.edit');
        if (!VariantAi::available($app)) {
            return self::answer(['ok' => false, 'message' => t('The AI is off.')], 409);
        }
        $name = trim(mb_substr($request->str('name'), 0, 191, 'UTF-8'));
        if ($name === '') {
            return self::answer(['ok' => false, 'message' => t('Write the name of the product first.')], 422);
        }
        $categories = CategoryAdmin::parentOptions($app, null);
        try {
            $proposal = VariantAi::fill($app, $name, $categories);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'catalog.product_fill');

            return self::answer(['ok' => false, 'message' => t('The AI could not help right now.')], 502);
        }

        return self::answer([
            'ok' => true,
            'summary' => $proposal['summary'],
            'category_id' => $proposal['category_id'],
            'category' => $proposal['category_id'] !== null ? (string) preg_replace('/^(?:— )+/u', '', (string) $categories[(string) $proposal['category_id']]) : '',
        ]);
    }

    /**
     * La tarjeta «Rellenar con IA» de la columna derecha del alta. Solo en un producto nuevo y solo si la IA está disponible.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row !== null || !$app->auth()->can('catalog.edit') || !VariantAi::available($app)) {
            return '';
        }

        $texts = ['summary' => t('Short description'), 'category' => t('Category'), 'apply' => t('Use the selected'), 'none' => t('The AI had no proposal.'),
            'name' => t('Write the name of the product first.'), 'fail' => t('The AI could not help right now.')];
        $attrs = '';
        foreach ($texts as $key => $text) {
            $attrs .= ' data-af-text-' . $key . '="' . e($text) . '"';
        }

        return '<div class="af-card" data-af-quickfill="' . e($app->url()->plain('/admin/catalog/products/quickfill')) . '" data-af-fill-lang="' . e($app->defaultLang()) . '"' . $attrs . '>'
            . '<div class="af-card-head"><h2>' . e(t('Fill in with AI')) . '</h2></div><div class="af-card-body af-stack">'
            . '<p class="af-small af-muted">' . e(t('Write the name and the AI proposes a short description and a category. You choose what to use.')) . '</p>'
            . '<button type="button" class="af-btn af-btn-sm" data-af-quickfill-run>' . Admin::icon('sparkles') . e(t('Fill in with AI')) . '</button>'
            . '<div data-af-quickfill-result hidden></div>'
            . '<script src="' . e($app->url()->asset('m', 'catalog', 'quickfill.js')) . '" defer></script>'
            . '</div></div>';
    }

    /**
     * Respuesta JSON del panel: sin caché, igual que los demás avisos de estado cambiante.
     *
     * @param array<string,mixed> $data
     */
    private static function answer(array $data, int $status = 200): Response
    {
        $response = Response::json($data, $status);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }
}
