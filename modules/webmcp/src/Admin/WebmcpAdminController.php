<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Webmcp\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Webmcp\Webmcp;

/**
 * El panel de WebMCP, de solo lectura (permiso «webmcp.view»): las herramientas que se publican y los formularios marcados,
 * cada uno con su estado. Los ajustes están en la pantalla de ajustes del módulo.
 */
final class WebmcpAdminController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('webmcp.view');
        /** @var Webmcp $webmcp */
        $webmcp = $app->service('webmcp');

        $tools = [];
        foreach ($webmcp->tools() as $name => $tool) {
            $tools[] = ['name' => $name, 'description' => $tool['description'], 'read_only' => $webmcp->readOnly($name, $tool['writes'])];
        }
        $placement = $webmcp->placement();
        $forms = [];
        foreach (Webmcp::FORMS as $form) {
            $info = $webmcp->describe($form, $form === 'update_cart_line' ? ['n' => 1, 'name' => '…'] : []);
            $forms[] = ['name' => $form, 'description' => $info !== null ? $info['description'] : '', 'fields' => $info !== null ? count($info['params']) : 0, 'templates' => $placement[$form]];
        }

        return Admin::page($app, 'webmcp::admin/index', ['enabled' => $webmcp->enabled(), 'tools' => $tools, 'forms' => $forms, 'url' => $app->url()->route('webmcp.tools', [], '')], [
            'title' => t('WebMCP'),
            'active' => '/admin/webmcp',
            'help' => Admin::helpText("«WebMCP» lets the AI assistant in a visitor's own browser use your shop for them; it is not a chat of the shop. The chat of the shop is «Assistant».\nThe tools are what an AI agent can do in the shop from the browser: search, look at a product, fill the cart and prepare the checkout. They are the same ones the assistant, the API and the MCP server use.\nThe order itself is never placed by a tool: the agent takes the visitor to the Place order button and the visitor confirms.\nThe forms are marked in the page so the agent knows what each one is for. A form without a template is defined but not shown in the shop yet."),
        ]);
    }
}
