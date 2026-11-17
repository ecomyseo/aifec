<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Reviews\Reviews;

/**
 * La bandeja de opiniones del panel: listado por estado, la ficha de una opinión y sus tres acciones (publicar, rechazar y
 * responder). Cada acción es un POST pequeño que llama al servicio «reviews» y vuelve a la ficha con un aviso: el panel no escribe
 * en la tabla por su cuenta, así la media del producto y la caché de la ficha siempre se actualizan.
 */
final class ReviewsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function reviews(): Reviews
    {
        /** @var Reviews $reviews */
        $reviews = $this->app->service('reviews');

        return $reviews;
    }

    // ------------------------------------------------------------------ bandeja

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('reviews.view');
        $table = new Table($app, $this->listConfig());
        $counts = $this->counts();
        $filters = $request->get('f', []);
        $filters = is_array($filters) ? $filters : [];
        $clean = $request->str('q') === '' && count($filters) <= 1;
        $tab = static function (string $value) use ($filters, $clean): bool {
            return $clean && count($filters) === 1 && isset($filters['status']) && (string) $filters['status'] === $value;
        };
        $tabs = [
            ['label' => t('Pending'), 'url' => $app->url()->plain('/admin/reviews', ['f' => ['status' => 'pending']]), 'count' => $counts['pending'], 'kind' => 'warn', 'active' => $tab('pending')],
            ['label' => t('Published'), 'url' => $app->url()->plain('/admin/reviews', ['f' => ['status' => 'published']]), 'count' => $counts['published'], 'kind' => 'ok', 'active' => $tab('published')],
            ['label' => t('Rejected'), 'url' => $app->url()->plain('/admin/reviews', ['f' => ['status' => 'rejected']]), 'count' => $counts['rejected'], 'kind' => '', 'active' => $tab('rejected')],
            ['label' => t('All'), 'url' => $app->url()->plain('/admin/reviews'), 'count' => $counts['all'], 'kind' => '', 'active' => $clean && $filters === []],
        ];

        return Admin::page($app, 'reviews::admin/list', ['tabs' => $tabs, 'table' => $table->render($request)], [
            'title' => t('Reviews'),
            'active' => '/admin/reviews',
            'help' => Admin::helpText("A new review waits here until you publish it, unless it is a verified purchase and you turned on automatic publishing in the settings.\nPublishing or rejecting a review updates the rating of the product straight away."),
        ]);
    }

    /** @param array<string,string> $params id */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('reviews.view');
        $review = $this->review((int) $params['id']);
        $product = $app->service('catalog')->product((int) $review['product_id'], null, true);
        $base = '/admin/reviews/' . (int) $review['id'];

        return Admin::page($app, 'reviews::admin/show', [
            'review' => $review,
            'product' => $product,
            'canEdit' => $app->auth()->can('reviews.edit'),
            'urls' => [
                'publish' => $app->url()->plain($base . '/publish'),
                'reject' => $app->url()->plain($base . '/reject'),
                'reply' => $app->url()->plain($base . '/reply'),
            ],
        ], [
            'title' => t('Review of {product}', ['product' => $product !== null ? (string) $product['name'] : '#' . (int) $review['product_id']]),
            'crumbs' => [[t('Reviews'), $app->url()->plain('/admin/reviews')]],
            'active' => '/admin/reviews',
        ]);
    }

    // ------------------------------------------------------------------ acciones

    /** @param array<string,string> $params id */
    public function publish(Request $request, array $params): Response
    {
        $review = $this->editable((int) $params['id']);
        if ($this->reviews()->publish((int) $review['id'])) {
            Admin::audit($this->app, 'publish', 'review', (string) $review['id'], 'Review published');
            Admin::flash($this->app, 'ok', t('The review is published.'));
        } else {
            Admin::flash($this->app, 'info', t('The review was already published.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/reviews/' . (int) $review['id']));
    }

    /** @param array<string,string> $params id */
    public function reject(Request $request, array $params): Response
    {
        $review = $this->editable((int) $params['id']);
        if ($this->reviews()->reject((int) $review['id'])) {
            Admin::audit($this->app, 'reject', 'review', (string) $review['id'], 'Review rejected');
            Admin::flash($this->app, 'ok', t('The review is rejected and no longer shown.'));
        } else {
            Admin::flash($this->app, 'info', t('The review was already rejected.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/reviews/' . (int) $review['id']));
    }

    /** @param array<string,string> $params id */
    public function reply(Request $request, array $params): Response
    {
        $review = $this->editable((int) $params['id']);
        try {
            $this->reviews()->reply((int) $review['id'], $request->str('reply'));
            Admin::audit($this->app, 'reply', 'review', (string) $review['id'], 'Reply to a review');
            Admin::flash($this->app, 'ok', t('The reply is saved.'));
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t('The reply is too long: {n} characters at most.', ['n' => Reviews::MAX_REPLY]));
        }

        return Response::redirect($this->app->url()->plain('/admin/reviews/' . (int) $review['id']));
    }

    // ------------------------------------------------------------------ interno

    /**
     * @return array<string,mixed>
     */
    private function review(int $id): array
    {
        $review = $this->reviews()->find($id);
        if ($review === null) {
            throw HttpError::notFound();
        }

        return $review;
    }

    /**
     * La opinión, comprobando que quien llama puede cambiarla.
     *
     * @return array<string,mixed>
     */
    private function editable(int $id): array
    {
        $this->app->auth()->requireCap('reviews.edit');

        return $this->review($id);
    }

    /**
     * @return array<string,mixed>
     */
    private function listConfig(): array
    {
        $app = $this->app;

        return [
            'from' => '{reviews} r LEFT JOIN {product_i18n} pi ON pi.product_id = r.product_id AND pi.lang = ?',
            'params' => [$app->defaultLang()],
            'select' => 'r.id, r.product_id, r.name, r.email, r.rating, r.title, r.body, r.status, r.verified, r.created_at, pi.name AS product_name',
            'key' => 'id',
            'key_sql' => 'r.id',
            'search' => ['r.name', 'r.email', 'r.title', 'r.body', 'pi.name'],
            'filters' => [
                'status' => ['label' => 'Status', 'column' => 'r.status', 'options' => ['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected']],
                'from' => ['label' => 'From', 'type' => 'date_from', 'column' => 'r.created_at'],
                'to' => ['label' => 'To', 'type' => 'date_to', 'column' => 'r.created_at'],
            ],
            'sort' => ['r.id', 'desc'],
            'per_page' => 25,
            'empty' => ['title' => 'No reviews yet', 'text' => 'Reviews show up here when a customer writes one on a product page.'],
            'row_url' => static function (array $row) use ($app): string {
                return $app->url()->plain('/admin/reviews/' . (int) $row['id']);
            },
            'columns' => [
                'created_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => 'r.created_at', 'hide_sm' => true],
                'product_name' => ['label' => 'Product', 'link' => true, 'render' => static function (array $row, App $app): string {
                    return e((string) $row['product_name'] !== '' ? (string) $row['product_name'] : '#' . (int) $row['product_id']);
                }],
                'rating' => ['label' => 'Rating', 'sort' => 'r.rating', 'render' => static function (array $row, App $app): string {
                    return '<span class="af-nowrap" aria-label="' . e(tn('{n} star', '{n} stars', (int) $row['rating'])) . '">' . e(str_repeat('★', (int) $row['rating']) . str_repeat('☆', 5 - (int) $row['rating'])) . '</span>';
                }],
                'name' => ['label' => 'Customer', 'render' => static function (array $row, App $app): string {
                    $text = (string) $row['title'] !== '' ? (string) $row['title'] : mb_substr((string) $row['body'], 0, 80, 'UTF-8');

                    return '<strong>' . e((string) $row['name']) . '</strong><br><span class="af-small af-muted">' . e($text) . '</span>';
                }],
                'verified' => ['label' => 'Verified purchase', 'type' => 'bool', 'hide_sm' => true],
                'status' => ['label' => 'Status', 'sort' => 'r.status', 'type' => 'badge', 'map' => [
                    'pending' => ['Pending', 'warn'], 'published' => ['Published', 'ok'], 'rejected' => ['Rejected', 'bad'],
                ]],
            ],
        ];
    }

    /**
     * @return array{pending:int,published:int,rejected:int,all:int}
     */
    private function counts(): array
    {
        $row = $this->app->db()->row(
            "SELECT COUNT(*) AS n_all, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS n_pending, "
            . "SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS n_published, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS n_rejected FROM {reviews}"
        );
        $row = is_array($row) ? $row : [];

        return [
            'all' => isset($row['n_all']) ? (int) $row['n_all'] : 0,
            'pending' => isset($row['n_pending']) ? (int) $row['n_pending'] : 0,
            'published' => isset($row['n_published']) ? (int) $row['n_published'] : 0,
            'rejected' => isset($row['n_rejected']) ? (int) $row['n_rejected'] : 0,
        ];
    }
}
