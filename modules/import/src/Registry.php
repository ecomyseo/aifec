<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

/**
 * Registro de lectores y escritores de la migración.
 *
 * Los lectores llegan por el filtro «import.readers» (lista de clases que implementan SourceReader). Los escritores,
 * por «import.writers» ([tipo => clase]); si nadie declara uno, se busca Writers\<TipoEnStudly>Writer (por ejemplo
 * TaxRatesWriter) y se usa si existe. Un tipo sin escritor, o cuyo módulo de destino no está activo, se salta entero y
 * el informe lo dice.
 */
final class Registry
{
    /** @var App */
    private $app;

    /** @var array<string,TypeWriter> */
    private $writers = [];

    /** @var array<string,array<string,mixed>>|null */
    private $described;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Fichas de los lectores disponibles, con su clase y un identificador estable («woocommerce:api»).
     *
     * @return array<string,array<string,mixed>> id => describe() + class
     */
    public function readers(): array
    {
        if ($this->described !== null) {
            return $this->described;
        }
        $list = $this->app->hooks()->apply('import.readers', [], $this->app);
        $out = [];
        foreach (is_array($list) ? $list : [] as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, SourceReader::class)) {
                continue;
            }
            try {
                $card = $class::describe();
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'import.describe');
                continue;
            }
            if (!isset($card['source'], $card['mode']) || !is_string($card['source']) || !is_string($card['mode'])) {
                continue;
            }
            $card['fields'] = isset($card['fields']) && is_array($card['fields']) ? $card['fields'] : [];
            $card['class'] = $class;
            $out[$card['source'] . ':' . $card['mode']] = $card;
        }
        $this->described = $out;

        return $out;
    }

    /**
     * La ficha de un lector por su origen y su modo, o null si no existe.
     *
     * @return array<string,mixed>|null
     */
    public function reader(string $source, string $mode): ?array
    {
        $all = $this->readers();

        return isset($all[$source . ':' . $mode]) ? $all[$source . ':' . $mode] : null;
    }

    /**
     * La clase del escritor de un tipo, o null si no hay.
     */
    public function writerClass(string $type): ?string
    {
        $declared = $this->app->hooks()->apply('import.writers', [], $this->app);
        $declared = is_array($declared) ? $declared : [];
        $class = isset($declared[$type]) && is_string($declared[$type]) ? $declared[$type] : __NAMESPACE__ . '\\Writers\\' . self::studly($type) . 'Writer';
        if (!class_exists($class) || !is_subclass_of($class, TypeWriter::class)) {
            return null;
        }

        return $class;
    }

    /**
     * El escritor de un tipo, ya creado (uno por tipo y petición), o null.
     */
    public function writer(string $type): ?TypeWriter
    {
        if (isset($this->writers[$type])) {
            return $this->writers[$type];
        }
        $class = $this->writerClass($type);
        if ($class === null) {
            return null;
        }
        try {
            $writer = new $class($this->app);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'import.writer:' . $type);

            return null;
        }
        $this->writers[$type] = $writer;

        return $writer;
    }

    /**
     * Por qué un tipo no se puede escribir aquí, o null si se puede. El texto va en inglés, con marcadores {nombre}
     * (se enseña con t($text, $vars)).
     *
     * @return array{text:string,vars:array<string,string>}|null
     */
    public function unavailable(string $type): ?array
    {
        if (!Types::valid($type)) {
            return ['text' => 'Unknown type', 'vars' => []];
        }
        $requires = Types::requires();
        foreach (isset($requires[$type]) ? $requires[$type] : [] as $module) {
            if (!$this->app->modules()->isLoaded($module)) {
                return ['text' => 'The «{module}» module is not active', 'vars' => ['module' => $module]];
            }
        }
        if ($this->writerClass($type) === null) {
            return ['text' => 'This version cannot write this type yet', 'vars' => []];
        }

        return null;
    }

    /** «tax_rates» → «TaxRates». */
    public static function studly(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
    }
}
