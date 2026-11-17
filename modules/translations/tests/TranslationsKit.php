<?php
/**
 * AIFEC — utilidades de las pruebas del módulo de traducciones (todas con el prefijo «trx»).
 *
 *   trxApp(array $ajustes = [], array $módulos = ['translations'], array $idiomas = ['es', 'fr']): App
 *       Sitio nuevo con esos módulos y esos idiomas, y una lista de cadenas de mentira (trxStrings()) en lugar de leer el código.
 *   trxAiApp(array $ajustes = [], array $idiomas = ['es', 'fr']): array{0: App, 1: TrxProvider}
 *       Lo mismo con la IA encendida (y el catálogo, los contenidos, el correo y la cola) y una IA de mentira que traduce
 *       poniendo «FR » delante de cada texto (o lo que se le diga) y guarda cada encargo.
 *   trxCodeCatalog(App $app, string $idioma, array $catálogo): void
 *       Un catálogo «del código» de prueba, a medias, en una carpeta temporal registrada en el motor de idiomas.
 *   trxWork(App $app): void
 *       Trabaja la cola hasta vaciarla.
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
use Aifec\Kernel\App;
use Aifec\Kernel\Cache;

require_once __DIR__ . '/../../catalog/tests/helpers.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

if (!class_exists('TrxProvider')) {
    /** IA de mentira: lee los textos del encargo y devuelve su «traducción» con la forma pedida; guarda cada encargo. */
    final class TrxProvider implements AiProvider
    {
        /** @var AiRequest[] */
        public $requests = [];

        /** @var callable|null (string $texto, string $formato): string — null = «FR » delante de cada forma */
        public $translate;

        /** @var string|null si no es null, lanza un fallo del proveedor con este mensaje */
        public $fail;

        /** @var bool devuelve una respuesta sin la forma pedida */
        public $garbage = false;

        public function name(): string
        {
            return 'trx';
        }

        public function generate(AiRequest $r): AiResult
        {
            $this->requests[] = $r;
            if ($this->fail !== null) {
                throw new AiException($this->fail, true, 503);
            }
            if ($this->garbage) {
                return new AiResult('no es JSON', [], null, 100, 10, 'stop', 'trx-model');
            }
            $items = [];
            foreach (trxItemsOf($r) as $item) {
                $text = (string) $item['text'];
                $format = (string) $item['format'];
                if ($this->translate !== null) {
                    $out = (string) call_user_func($this->translate, $text, $format);
                } else {
                    $out = implode('|', array_map(static function (string $form): string {
                        return 'FR ' . $form;
                    }, explode('|', $text)));
                }
                $items[] = ['id' => (string) $item['id'], 'text' => $out];
            }
            $json = ['items' => $items];

            return new AiResult((string) json_encode($json, JSON_UNESCAPED_UNICODE), [], $json, 200, 100, 'stop', 'trx-model');
        }

        public function embed(array $texts, string $model = ''): array
        {
            return [];
        }
    }
}

/**
 * Los textos (id, format, text) que viajan en el bloque ITEMS de un encargo.
 *
 * @return array<int,array<string,string>>
 */
function trxItemsOf(AiRequest $r): array
{
    $content = $r->messages !== [] ? $r->messages[0]->content : '';
    if (preg_match('/<<<DATA ITEMS (\w+)>>>\n(.*)\n<<<END ITEMS \1>>>/s', $content, $m) !== 1) {
        return [];
    }
    $items = json_decode($m[2], true);

    return is_array($items) ? $items : [];
}

/** Todo lo que viajó en los encargos de una IA de mentira, junto. */
function trxSent(TrxProvider $ai): string
{
    $out = '';
    foreach ($ai->requests as $r) {
        $out .= $r->system . "\n";
        foreach ($r->messages as $m) {
            $out .= $m->content . "\n";
        }
    }

    return $out;
}

/** @return array<string,array{area:string,origin:string}> */
function trxStrings(): array
{
    return [
        'Add to cart' => ['area' => 'shop', 'origin' => 'catalog'],
        'Checkout' => ['area' => 'shop', 'origin' => 'checkout'],
        'Hello {name}' => ['area' => 'shop', 'origin' => 'core'],
        '{n} item|{n} items' => ['area' => 'shop', 'origin' => 'core'],
        'Orders' => ['area' => 'panel', 'origin' => 'orders'],
        'Save' => ['area' => 'panel', 'origin' => 'core'],
    ];
}

/**
 * @param array<string,mixed> $settings
 * @param string[]            $modules
 * @param string[]            $languages
 */
function trxApp(array $settings = [], array $modules = ['translations'], array $languages = ['es', 'fr']): App
{
    $app = testApp(['modules' => $modules, 'settings' => $settings + ['i18n.languages' => $languages]]);
    $app->boot();
    trxBindStrings($app, trxStrings());

    return $app;
}

/** Cambia la lista de cadenas «del código» de un sitio de prueba. @param array<string,array{area:string,origin:string}> $strings */
function trxBindStrings(App $app, array $strings): void
{
    $app->bind('translations.strings', static function () use ($strings): \ArrayObject {
        return new \ArrayObject($strings);
    });
}

/**
 * @param array<string,mixed> $settings
 * @param string[]            $languages
 *
 * @return array{0:App,1:TrxProvider}
 */
function trxAiApp(array $settings = [], array $languages = ['es', 'fr']): array
{
    $settings += [
        'ai.enabled' => true,
        'ai.daily_limit_cents' => 200,
        'ai.daily_limit_calls' => 500,
        'store.name' => 'Muebles Roble & Cía',
        'translations.sector' => 'muebles de madera',
    ];
    foreach (['economy', 'standard', 'advanced'] as $tier) {
        $settings += ['ai.' . $tier . '.price_in' => 100, 'ai.' . $tier . '.price_out' => 400];
    }
    $app = trxApp($settings, ['translations', 'ai', 'catalog', 'cms', 'media', 'mail'], $languages);
    $ai = new TrxProvider();
    foreach (['economy', 'standard', 'advanced'] as $tier) {
        $app->bind('ai.provider.' . $tier, static function () use ($ai): TrxProvider {
            return $ai;
        });
    }

    return [$app, $ai];
}

/** @param array<string,string> $catalog */
function trxCodeCatalog(App $app, string $lang, array $catalog): void
{
    $dir = tmpDir('aifec-trx-code');
    check(Cache::export($dir . '/' . $lang . '.php', $catalog), 'catálogo de prueba escrito');
    $app->i18n()->addDir($dir);
}

/** Trabaja la cola hasta que no queda nada que hacer (con un tope por si algo se encolara solo para siempre). */
function trxWork(App $app): void
{
    for ($i = 0; $i < 20; $i++) {
        $work = $app->service('jobs')->work(30, 100);
        if ($work['done'] + $work['failed'] + $work['retried'] === 0) {
            return;
        }
    }
}

/** El trabajo de traducción con ese id. @return array<string,mixed> */
function trxJob(App $app, int $id): array
{
    $row = $app->db()->row('SELECT * FROM {translation_jobs} WHERE id = ?', [$id]);
    check($row !== null, 'el trabajo ' . $id . ' existe');

    return (array) $row;
}

/** Una persona con un papel a medida y su navegador ya dentro del panel. @param string[] $caps */
function trxPanel(App $app, string $role, array $caps): TestClient
{
    $app->db()->insert('roles', ['code' => $role, 'name' => ucfirst($role), 'caps' => json_encode($caps), 'system' => 0]);

    return adminClient($app, $role);
}
