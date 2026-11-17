<?php
/**
 * AIFEC — ayudas de las pruebas de la segunda ronda de clientes (P088).
 *
 *   secondSpy(App $app): SecondSearchSpy
 *       Pone delante del buscador de la tienda un espía que cuenta cada Search::ask() (->calls: query y opciones) y deja contestar
 *       al buscador de verdad; con ->force se le puede dar otra respuesta a una frase.
 *
 *   secondLampShop(): array
 *       [App, ids, AssistantFakeAi, TestClient, id de la categoría «Flexos»]: una tienda con un flexo de 59,90 €, uno de 29,90 €, una
 *       lámpara de pie de 129 €, una silla con colores y las sillas de la tienda pequeña del buscador.
 *
 *   secondChair(App $app): int
 *       Una silla con dos colores (negro y gris) con existencias, para probar el añadir con opciones.
 *
 *   secondAsk(AssistantFakeAi $ai): array
 *       Las peticiones a la IA que son del asistente (no las del buscador).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/FastKit.php';

if (!class_exists('SecondSearchSpy')) {
    /** Un buscador que cuenta las veces que se le pide Search::ask() y deja pasar todo lo demás. */
    final class SecondSearchSpy
    {
        /** @var array<int,array{query:string,options:array<string,mixed>}> */
        public $calls = [];

        /** @var callable|null recibe (frase, opciones) y devuelve lo que contesta ask() en su lugar, o null para dejar pasar */
        public $force;

        /** @var object */
        private $real;

        public function __construct(object $real)
        {
            $this->real = $real;
        }

        /**
         * @param array<string,mixed> $options
         *
         * @return array<string,mixed>
         */
        public function ask(string $query, array $options = []): array
        {
            $this->calls[] = ['query' => $query, 'options' => $options];
            if ($this->force !== null) {
                $forced = call_user_func($this->force, $query, $options);
                if (is_array($forced)) {
                    return $forced;
                }
            }

            return $this->real->ask($query, $options);
        }

        /**
         * @param array<int,mixed> $arguments
         *
         * @return mixed
         */
        public function __call(string $name, array $arguments)
        {
            return $this->real->$name(...$arguments);
        }

        /** Cuántas veces se pidió con la IA permitida. */
        public function withAi(): int
        {
            $n = 0;
            foreach ($this->calls as $call) {
                if (!array_key_exists('ai', $call['options']) || $call['options']['ai'] !== false) {
                    ++$n;
                }
            }

            return $n;
        }
    }
}

if (!function_exists('secondSpy')) {
    function secondSpy(App $app): SecondSearchSpy
    {
        $spy = new SecondSearchSpy(searchService($app));
        $app->bind('search', static function () use ($spy): SecondSearchSpy {
            return $spy;
        });

        return $spy;
    }

    /**
     * @return array{0:App,1:array<string,int>,2:AssistantFakeAi,3:TestClient,4:int}
     */
    function secondLampShop(): array
    {
        $app = assistantApp();
        $ids = assistantShop($app);
        $flexos = aifecCategory($app, 'Flexos');
        $lamparas = aifecCategory($app, 'Iluminación');
        $ids['flexo'] = aifecProduct($app, ['name' => 'Flexo LED Arco', 'summary' => 'Flexo de escritorio con luz LED regulable', 'categories' => [$flexos], 'price' => 5990, 'stock' => 5]);
        $ids['flexo_barato'] = aifecProduct($app, ['name' => 'Flexo Básico Estudio', 'summary' => 'Flexo sencillo para estudiar', 'categories' => [$flexos], 'price' => 2990, 'stock' => 5]);
        $ids['faro'] = aifecProduct($app, ['name' => 'Lámpara de Pie Faro', 'summary' => 'Lámpara de salón', 'categories' => [$lamparas], 'price' => 12900, 'stock' => 5]);
        $ids['silla_color'] = secondChair($app);
        searchService($app)->reindexNow();
        $ai = assistantAi($app);

        return [$app, $ids, $ai, new TestClient($app), $flexos];
    }

    function secondChair(App $app): int
    {
        aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'gris' => 'Gris'], ['variant' => 1]);
        $id = aifecProduct($app, ['name' => 'Silla Ergo Pro', 'price' => 24900, 'variants' => [
            ['sku' => 'ERGO-N', 'label' => 'Negro', 'price' => 24900, 'stock' => 4, 'values' => ['color' => 'negro']],
            ['sku' => 'ERGO-G', 'label' => 'Gris', 'price' => 24900, 'stock' => 4, 'values' => ['color' => 'gris']],
        ]]);
        $app->invalidate(['catalog']);
        searchService($app)->reindexNow();

        return $id;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function secondAsk(AssistantFakeAi $ai): array
    {
        return array_values($ai->asked);
    }
}
