<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Ai\AiException;
use Aifec\Ai\Prompt;
use Aifec\Kernel\App;
use Aifec\Modules\Ai\Generators\AltGenerator;
use Aifec\Modules\Ai\Generators\DescriptionGenerator;
use Aifec\Modules\Ai\Generators\FactsGenerator;
use Aifec\Modules\Ai\Generators\FaqsGenerator;
use Aifec\Modules\Ai\Generators\Generator;
use Aifec\Modules\Ai\Generators\MetaDescriptionGenerator;
use Aifec\Modules\Ai\Generators\MetaTitleGenerator;
use Aifec\Modules\Ai\Generators\PageMetaGenerator;
use Aifec\Modules\Ai\Generators\SummaryGenerator;
use Aifec\Modules\Ai\Generators\TranslateGenerator;

/**
 * Las tareas de redacción: pide el texto a la IA (servicio «ai»), comprueba que la respuesta cumple el esquema y crea una
 * propuesta pendiente. Nunca escribe en la tienda: eso lo hace una persona al aprobar.
 *
 * Tareas: description, summary, facts, faqs, meta_title, meta_description, alt (una imagen de la biblioteca) y translate (cualquier
 * campo de producto a otro idioma).
 */
final class Generators
{
    /** Las tareas que trabajan sobre un producto (las que puede lanzar un lote). */
    public const PRODUCT_TASKS = ['description', 'summary', 'facts', 'faqs', 'meta_title', 'meta_description', 'translate'];

    /** Todas las tareas. */
    public const TASKS = ['description', 'summary', 'facts', 'faqs', 'meta_title', 'meta_description', 'alt', 'translate', 'page_meta_title', 'page_meta_description'];

    /** Las tareas que trabajan sobre una página de contenido. */
    public const PAGE_TASKS = ['page_meta_title', 'page_meta_description'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * El generador de una tarea.
     *
     * @throws \InvalidArgumentException si la tarea no existe
     */
    public static function generator(string $task): Generator
    {
        switch ($task) {
            case 'description':
                return new DescriptionGenerator();
            case 'summary':
                return new SummaryGenerator();
            case 'facts':
                return new FactsGenerator();
            case 'faqs':
                return new FaqsGenerator();
            case 'meta_title':
                return new MetaTitleGenerator();
            case 'meta_description':
                return new MetaDescriptionGenerator();
            case 'alt':
                return new AltGenerator();
            case 'translate':
                return new TranslateGenerator();
            case 'page_meta_title':
                return new PageMetaGenerator('meta_title');
            case 'page_meta_description':
                return new PageMetaGenerator('meta_description');
        }
        throw new \InvalidArgumentException('Unknown AI task: ' . $task);
    }

    /**
     * Lanza una tarea sobre un producto (o, con «alt», sobre una imagen de la biblioteca) y devuelve el id de la propuesta.
     *
     * $options: lang (idioma de la propuesta; el principal por defecto), to (idioma de destino de translate), field (campo que
     * traduce translate), product_id y position (para «alt»: de qué producto es la imagen).
     *
     * @param array<string,mixed> $options
     *
     * @throws \InvalidArgumentException si la tarea, el producto o los datos no valen
     * @throws AiException               si la IA está apagada, en su tope, falla o contesta algo que no vale
     */
    public function run(string $task, int $id, array $options = []): int
    {
        $generator = self::generator($task);
        $lang = $this->language(isset($options['lang']) && is_string($options['lang']) ? $options['lang'] : '');
        $context = [];
        $requestOptions = [];

        if ($task === 'alt') {
            $context = $this->imageContext($id, isset($options['product_id']) ? (int) $options['product_id'] : 0, $lang);
        } elseif (in_array($task, self::PAGE_TASKS, true)) {
            $page = $this->app->has('cms') ? $this->app->service('cms')->page($id, $lang, true) : null;
            if (!is_array($page)) {
                throw new \InvalidArgumentException('Unknown page');
            }
            $context = ['page' => $page];
        } else {
            $product = $this->product($id, $lang);
            $context = ['product' => $product];
            if ($task === 'translate') {
                list($generator, $lang, $requestOptions) = $this->translation($generator, $id, $options);
            }
        }

        $ai = $this->ai();
        $request = $generator->request($context, $lang, $requestOptions);
        $result = $ai->ask($generator->task(), $request, $generator->tier());
        $json = $result->json;
        if ($json === null) {
            $json = Prompt::decodeJson($result->text);
        }
        $text = is_array($json) ? $generator->proposed($json, $context) : null;
        if ($text === null || trim($text) === '') {
            $ai->flagFailure($ai->lastUsageId(), 'The answer does not match the expected shape');

            throw new AiException('The AI answer does not match the expected shape');
        }

        $entityLang = $generator->entity() === 'media' ? null : $lang;
        try {
            return $ai->propose($generator->entity(), $id, $generator->field(), $entityLang, $text, [
                'model' => $result->model !== '' ? $result->model : $ai->model($generator->tier()),
                'task' => $generator->task(),
            ]);
        } catch (\InvalidArgumentException $e) {
            $ai->flagFailure($ai->lastUsageId(), 'The answer does not match the expected shape');

            throw new AiException('The AI answer does not match the expected shape');
        }
    }

    // ------------------------------------------------------------------ interior

    private function ai(): Ai
    {
        /** @var Ai $ai */
        $ai = $this->app->service('ai');

        return $ai;
    }

    private function language(string $lang): string
    {
        $lang = strtolower(trim($lang));
        if ($lang === '') {
            return $this->app->defaultLang();
        }
        if (!in_array($lang, $this->app->languages(), true)) {
            throw new \InvalidArgumentException('Unknown language: ' . $lang);
        }

        return $lang;
    }

    /**
     * @return array<string,mixed>
     */
    private function product(int $id, string $lang): array
    {
        if (!$this->app->has('catalog')) {
            throw new \InvalidArgumentException('The catalog is not available');
        }
        $product = $this->app->service('catalog')->product($id, $lang, true);
        if (!is_array($product)) {
            throw new \InvalidArgumentException('Unknown product');
        }

        return $product;
    }

    /**
     * Lo que necesita «alt»: la imagen y, si se sabe de qué producto es, su ficha y la posición en la galería.
     *
     * @return array<string,mixed>
     */
    private function imageContext(int $mediaId, int $productId, string $lang): array
    {
        $db = $this->app->db();
        if (!$db->tableExists('media')) {
            throw new \InvalidArgumentException('The media library is not available');
        }
        $media = $db->row('SELECT id, filename, alt FROM {media} WHERE id = ?', [$mediaId]);
        if ($media === null) {
            throw new \InvalidArgumentException('Unknown image');
        }
        $context = ['image' => ['filename' => (string) $media['filename'], 'alt' => (string) ($media['alt'] === null ? '' : $media['alt'])]];
        if ($productId > 0) {
            $product = $this->product($productId, $lang);
            $context['product'] = $product;
            $position = 0;
            $total = 0;
            foreach (isset($product['images']) && is_array($product['images']) ? $product['images'] : [] as $index => $image) {
                ++$total;
                if (is_array($image) && isset($image['id']) && (int) $image['id'] === $mediaId) {
                    $position = $index + 1;
                }
            }
            if ($position > 0) {
                $context['image']['position'] = $position;
                $context['image']['total'] = $total;
            }
        }

        return $context;
    }

    /**
     * Prepara la traducción: el generador del campo, el idioma de destino y el texto de origen (el del idioma principal).
     *
     * @param array<string,mixed> $options
     *
     * @return array{0:Generator,1:string,2:array<string,mixed>}
     */
    private function translation(Generator $generator, int $productId, array $options): array
    {
        $field = isset($options['field']) && is_string($options['field']) && $options['field'] !== '' ? $options['field'] : 'description';
        $to = $this->language(isset($options['to']) && is_string($options['to']) ? $options['to'] : '');
        $from = $this->app->defaultLang();
        if ($to === $from) {
            throw new \InvalidArgumentException('The target language is the main language');
        }
        if (!$generator instanceof TranslateGenerator) {
            throw new \InvalidArgumentException('Not a translation task');
        }
        $source = ProductFields::read($this->app, $productId, $from, $field);
        if (trim($source) === '') {
            throw new \InvalidArgumentException('There is nothing to translate');
        }

        return [$generator->forField($field), $to, ['source' => $source]];
    }
}
