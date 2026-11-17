<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La pantalla del panel con los últimos eventos que mandó el servidor y lo que contestó cada servicio.
 */
final class DatalayerAdmin
{
    /** Cuántos eventos se enseñan. */
    private const SHOWN = 50;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/datalayer/events
     *
     * @param array<string,string> $params
     */
    public function events(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('datalayer.view');
        $db = $this->app->db();
        $rows = $db->select(
            'SELECT e.id, e.kind, e.destination, e.event_id, e.status, e.http_status, e.response, e.created_at, e.sent_at, o.number FROM {datalayer_events} e LEFT JOIN {orders} o ON o.id = e.order_id ORDER BY e.id DESC LIMIT ?',
            [self::SHOWN]
        );
        $counts = [];
        foreach ($db->select('SELECT status, COUNT(*) AS n FROM {datalayer_events} GROUP BY status', []) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        /** @var Datalayer $datalayer */
        $datalayer = $this->app->service('datalayer');

        return Admin::page($this->app, 'datalayer::admin/events', [
            'rows' => $rows, 'counts' => $counts, 'serverOn' => $datalayer->config()->serverEvents(),
            'settingsUrl' => $this->app->url()->plain('/admin/settings/datalayer'),
        ], [
            'title' => t('Server events'),
            'active' => '/admin/datalayer/events',
            'help' => Admin::helpText("Here you see what the server sent to Google Analytics and Meta, and what each one answered.\nOnly purchases and refunds that happen after you turn the switch on are sent. Old orders are never sent.\nAn event is only sent if the visitor accepted the cookies of that service when they placed the order. The others show «skipped».\nThe same event identifier is used in the browser and here, so nothing is counted twice."),
        ]);
    }
}
