<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\SearchLearning\Engine\Scope;

require_once __DIR__ . '/helpers.php';

test('instalar y leer aprendizaje no llama a la API ni inicia rondas; GET no autoriza una ronda', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture();
    same([], $provider->requests);
    same([], $engine->recent());
    same(0, $engine->version('es'));
    $app->request()->method = 'GET';
    throws(static function () use ($engine, $actor): void { $engine->createRun(learningEngineOptions($engine), $actor); }, \RuntimeException::class);
    same([], $provider->requests);
    same([], $engine->recent());
});

test('ni otro papel con comodín ni un administrador demo pueden iniciar consumo', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture();
    $app->db()->update('users', ['role' => 'manager'], ['id' => $actor]);
    $app->db()->update('roles', ['caps' => '["*"]'], ['code' => 'manager']);
    throws(static function () use ($engine, $actor): void { $engine->createRun(learningEngineOptions($engine), $actor); }, \RuntimeException::class);
    $app->db()->update('users', ['role' => 'admin'], ['id' => $actor]);
    $app->site()->config['demo'] = true;
    throws(static function () use ($engine, $actor): void { $engine->createRun(learningEngineOptions($engine), $actor); }, \RuntimeException::class);
    same([], $provider->requests);
});

test('la huella incluye URL completa, sitio e idioma y rechaza formularios antiguos', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture();
    $old = learningEngineOptions($engine);
    $app->site()->config['url'] = 'https://tienda.test/otra-tienda';
    check($old['expected_context'] !== $engine->context('es')['hash']);
    throws(static function () use ($engine, $actor, $old): void { $engine->createRun($old, $actor); }, \RuntimeException::class);
    same([], $provider->requests);
    foreach (['hola@cliente.test', 'teléfono 999000001', 'https://otro.test', 'api_key=secreto-de-prueba'] as $unsafe) {
        throws(static function () use ($unsafe): void { Scope::text($unsafe); }, \InvalidArgumentException::class);
    }
});

test('cada tanda vuelve a comprobar usuario activo y no llama al proveedor tras revocar su permiso', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture();
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $app->db()->update('users', ['active' => 0], ['id' => $actor]);
    same('paused', $engine->tick($id));
    same([], $provider->requests);
    same('learning.real_admin_required', $engine->find($id)['error']);
});

test('cancelar después de recibir una respuesta impide guardar propuestas o aplicar memoria', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function () use ($engine, $id, $actor): void { $engine->cancel($id, $actor); };
    same('cancelled', $engine->tick($id));
    same([], $engine->proposals($id));
    same(1, $engine->find($id)['calls']);
    same('cancelled', $engine->find($id)['status']);
    same(0, $engine->version('es'));
});

test('cambiar URL durante la API conserva el gasto y descarta la respuesta para el nuevo contexto', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function () use ($app): void { $app->site()->config['url'] = 'https://tienda.test/movida'; };
    same('paused', $engine->tick($id));
    same([], $engine->proposals($id));
    same('learning.context_changed', $engine->find($id)['error']);
    same(150, $engine->find($id)['tokens_in']);
});

test('borrar la configuración autoritativa durante la llamada no vuelve al contexto en caché', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $app->site()->id = basename($app->site()->dataDir);
    $file = $app->site()->dataDir . '/config.php';
    file_put_contents($file, '<?php return ' . var_export($app->site()->config, true) . ';');
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    same(true, $engine->find($id)['context']['config_backed']);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function () use ($file): void { unlink($file); };
    same('paused', $engine->tick($id));
    same('learning.context_changed', $engine->find($id)['error']);
    same([], $engine->proposals($id));
    same(150, $engine->find($id)['tokens_in']);
});

test('quitar el idioma de la configuración durante una llamada descarta los candidatos', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function () use ($app): void { $app->settings()->set('i18n.languages', ['en']); };
    same('paused', $engine->tick($id));
    same('learning.language', $engine->find($id)['error']);
    same([], $engine->proposals($id));
});
