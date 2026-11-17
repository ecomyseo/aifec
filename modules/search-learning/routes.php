<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\SearchLearning\Admin\LearningController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/search/learning', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [LearningController::class, 'index'])->name('admin.search_learning');
        $r->post('/runs', [LearningController::class, 'create'])->name('admin.search_learning.create');
        $r->get('/runs/{id:\d+}', [LearningController::class, 'show'])->name('admin.search_learning.show');
        $r->get('/runs/{id:\d+}/report/{kind:experience|improvements}', [LearningController::class, 'download'])->name('admin.search_learning.report');
        $r->post('/runs/{id:\d+}/{action:resume|cancel|validate|apply|revert}', [LearningController::class, 'action'])->name('admin.search_learning.action');
    });
};
