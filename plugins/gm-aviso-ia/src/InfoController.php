<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\AvisoIa;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La página pública que cuenta cómo se redactan los textos. Sin datos de nadie: se guarda en la caché de página.
 */
final class InfoController
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
    public function show(Request $request, array $params): Response
    {
        $title = t('How the texts of this shop are written');

        return $this->app->render('gm-aviso-ia::front/info', [
            'page' => ['type' => 'page', 'title' => $title, 'description' => t('What the AI writes, what a person checks and how you can tell us about a mistake.'), 'breadcrumbs' => []],
            'title' => $title,
        ])->cacheable(3600, ['gm-aviso-ia']);
    }
}
