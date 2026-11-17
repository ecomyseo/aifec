<?php
/**
 * AIFEC — ayudas de prueba del módulo de IA. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   aiApp(array $ajustes = [], array $idiomas = ['es']): App
 *       Aplicación con la IA, el catálogo, los medios y las tareas; la IA encendida, con precios puestos y un proveedor simulado
 *       publicado en cada nivel («ai.provider.<nivel>»). NINGUNA prueba llama a una IA de verdad.
 *
 *   aiFake(App $app, string $nivel = 'economy'): FakeProvider
 *       El proveedor simulado de un nivel: guarda en ->requests lo que se le pide y se le encolan respuestas con ->script().
 *
 *   aiJson(array $datos, int $entrada = 100, int $salida = 50): AiResult
 *       Una respuesta con JSON válido, como la que da un modelo cuando se le pidió un esquema.
 *
 *   aiRows(App $app, string $tabla, string $donde = '1 = 1', array $params = []): array
 *       Filas de ai_usage, ai_proposals, ai_batches o ai_marks.
 *
 *   aiProduct(App $app, array $datos = []): int
 *       Producto de prueba del catálogo (ver aifecProduct()).
 *
 * Precios de prueba (céntimos por millón de fichas): entrada 100 y salida 400 en los tres niveles. Una llamada de 10.000 fichas de
 * entrada cuesta exactamente un céntimo (1.000.000 de millonésimas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiResult;
use Aifec\Ai\FakeProvider;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../catalog/tests/helpers.php';

if (!function_exists('aiApp')) {
    /**
     * @param array<string,mixed> $settings
     * @param string[]            $languages
     */
    function aiApp(array $settings = [], array $languages = ['es']): App
    {
        $settings += [
            'ai.enabled' => true,
            'ai.daily_limit_cents' => 200,
            'i18n.languages' => $languages,
        ];
        foreach (['economy', 'standard', 'advanced'] as $tier) {
            $settings += ['ai.' . $tier . '.price_in' => 100, 'ai.' . $tier . '.price_out' => 400];
        }
        $app = testApp(['modules' => ['ai', 'catalog', 'media'], 'settings' => $settings]);
        $app->boot();
        foreach (['economy', 'standard', 'advanced'] as $tier) {
            $fake = new FakeProvider();
            $app->bind('ai.provider.' . $tier, static function () use ($fake): FakeProvider {
                return $fake;
            });
        }

        return $app;
    }

    function aiFake(App $app, string $tier = 'economy'): FakeProvider
    {
        /** @var FakeProvider $fake */
        $fake = $app->service('ai.provider.' . $tier);

        return $fake;
    }

    /**
     * @param array<mixed> $data
     */
    function aiJson(array $data, int $in = 100, int $out = 50): AiResult
    {
        return new AiResult((string) json_encode($data, JSON_UNESCAPED_UNICODE), [], $data, $in, $out, 'stop', 'fake-model');
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     */
    function aiRows(App $app, string $table, string $where = '1 = 1', array $params = []): array
    {
        return $app->db()->select('SELECT * FROM {' . $table . '} WHERE ' . $where . ' ORDER BY id', $params);
    }

    /**
     * @param array<string,mixed> $data
     */
    function aiProduct(App $app, array $data = []): int
    {
        return aifecProduct($app, $data);
    }

    /** Un proveedor que siempre falla y que, como los de verdad, repite la clave en su mensaje. */
    final class AiFailingProvider implements AiProvider
    {
        /** @var string */
        private $message;

        public function __construct(string $message)
        {
            $this->message = $message;
        }

        public function name(): string
        {
            return 'failing';
        }

        public function generate(AiRequest $r): AiResult
        {
            throw new AiException($this->message, true, 503);
        }

        public function embed(array $texts, string $model = ''): array
        {
            throw new AiException($this->message, true, 503);
        }
    }
}
