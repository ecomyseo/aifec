<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../../search/tests/helpers.php';

function learningPanelFixture(): array
{
    $app = searchApp(['modules.active' => ['catalog', 'media', 'search', 'ai', 'jobs', 'search-learning'], 'ai.enabled' => true,
        'ai.economy.provider' => 'fake', 'ai.standard.provider' => 'fake', 'ai.advanced.provider' => 'fake',
        'i18n.languages' => ['es', 'en']], ['ai', 'jobs', 'search-learning']);
    check($app->http() instanceof FakeHttpClient, 'ninguna prueba del panel usa una API real');
    aifecProduct($app, ['name' => 'Tocador de madera de ejemplo', 'summary' => 'Mueble de prueba para maquillarse.', 'stock' => 10, 'price' => 10000]);
    $app->service('search')->reindexNow();
    return [$app, adminClient($app)];
}

function learningPanelForm(TestClient $web, array $changes = []): array
{
    $response = $web->get('/admin/search/learning');
    same(200, $response->status);
    return $changes + ['_token' => aifecToken($web), 'expected_context' => inputValue($response->body, 'expected_context'),
        'expected_version' => inputValue($response->body, 'expected_version'), 'mode' => 'simulated', 'lang' => 'es', 'profiles' => '1', 'tier' => 'economy', 'budget' => '0,50', 'authorise' => '1'];
}

function learningPanelStart(TestClient $web, array $changes = []): int
{
    $response = $web->post('/admin/search/learning/runs', learningPanelForm($web, $changes));
    same(303, $response->status, 'el superadministrador encola la ejecución de prueba sin ejecutar IA');
    check(preg_match('~/admin/search/learning/runs/(\d+)$~', $response->headers['Location'], $match) === 1, 'se vuelve a la ronda creada');
    return (int) $match[1];
}

function learningPanelVersion(App $app, int $version): void
{
    $hash = $app->service('search_learning')->context('es')['hash'];
    $id = $app->db()->value('SELECT id FROM {learning_states} WHERE context_hash = ?', [$hash]);
    if ($id === null) {
        $app->db()->insert('learning_states', ['context_hash' => $hash, 'version' => $version, 'memory' => '[]', 'updated_at' => $app->clock()->sql()]);
    } else {
        $app->db()->update('learning_states', ['version' => $version], ['id' => (int) $id]);
    }
}

function learningPanelNoCalls(App $app): void
{
    same(0, count($app->http()->requests));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {ai_usage}'));
}
