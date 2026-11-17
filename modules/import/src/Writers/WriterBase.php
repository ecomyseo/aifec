<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Commerce\Money;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;
use Aifec\Modules\Import\ColumnWidths;
use Aifec\Modules\Import\FittedDb;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Redactor;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Types;
use Aifec\Modules\Import\WriteContext;
use Aifec\Text\HtmlSanitizer;
use Aifec\Util\Str;

/**
 * Lo que comparten los escritores de configuración y de catálogo (y los de clientes y pedidos): casar un registro,
 * decidir qué hacer con él, quedarse con los textos de los idiomas del sitio, pasar los precios a como se escriben aquí
 * y envolver cada registro en una transacción.
 *
 * Un escritor hereda esta clase y escribe solo store(): decide, escribe (si no es un simulacro) y devuelve el resultado.
 * Esta clase llama a store() dentro de una transacción: si lanza o si devuelve «skipped», no queda nada a medias.
 */
abstract class WriterBase implements TypeWriter
{
    /** @var App */
    protected $app;

    /** @var string[] valores secretos del registro en curso: nunca salen en un motivo */
    protected $secrets = [];

    /** @var string */
    private $currentId = '';

    /** @var array<string,bool> idiomas de fuera ya contados para el registro en curso */
    private $seenLanguages = [];

    /** @var array<string,bool> notas ya puestas para el registro en curso */
    private $noted = [];

    /** @var bool true en la segunda pasada: los textos se leen otra vez y no se vuelve a contar ni a avisar de nada */
    protected $quiet = false;

    /** @var FittedDb|null la base de datos con los textos largos ya ajustados a su columna */
    private $fitted;

    /** @var array<string,array{0:int,1:int}> «tabla.columna» => [largo que traía, largo que cabe] de lo recortado en el registro en curso */
    private $cuts = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Escribe (o simula) un registro. No lanza: un fallo es «skipped» con su motivo.
     *
     * @param array<string,mixed> $record
     *
     * @return array{result:string,id:?int,reason:string}
     */
    final public function write(array $record, WriteContext $ctx): array
    {
        $this->currentId = isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '';
        $this->seenLanguages = [];
        $this->noted = [];
        $this->cuts = [];

        $outcome = $this->safe(function () use ($record, $ctx): array {
            return $this->store($record, $ctx);
        }, $ctx);
        if ($this->cuts !== [] && !$ctx->dryRun() && $outcome['result'] !== self::SKIPPED) {
            $this->noteCuts($ctx);
        }
        $this->cuts = [];

        return $outcome;
    }

    /**
     * La base de datos para escribir: cada texto corto que sea más largo que su columna se recorta antes (ColumnWidths lee la anchura del
     * esquema). Los escritores la piden aquí y no a App, para que ningún registro se descarte por «Data too long».
     */
    protected function db(): FittedDb
    {
        if ($this->fitted === null) {
            $real = $this->app->db();
            $this->fitted = new FittedDb($real, new ColumnWidths($real), function (string $table, string $column, int $from, int $to): void {
                $key = $table . '.' . $column;
                $this->cuts[$key] = [isset($this->cuts[$key]) ? max($this->cuts[$key][0], $from) : $from, $to];
            });
        }

        return $this->fitted;
    }

    /**
     * Una sola nota por registro con todo lo que se recortó: «product_i18n.name (500 → 191)».
     */
    private function noteCuts(WriteContext $ctx): void
    {
        $parts = [];
        foreach ($this->cuts as $field => $pair) {
            $parts[] = $field . ' (' . $pair[0] . ' → ' . $pair[1] . ')';
        }
        $ctx->note($this->type(), $this->currentId, 'note', 'Some texts were longer than their field and were cut: ' . implode(', ', $parts));
    }

    /**
     * Decide y escribe un registro (o solo decide, en simulacro).
     *
     * @param array<string,mixed> $record
     *
     * @return array{result:string,id:?int,reason:string}
     */
    abstract protected function store(array $record, WriteContext $ctx): array;

    public function link(array $record, WriteContext $ctx): void
    {
    }

    public function checksums(WriteContext $ctx): array
    {
        return [];
    }

    // ------------------------------------------------------------------ decidir

    /**
     * Qué hacer con un registro ya casado: crear, actualizar, dejarlo como está o chocar con una edición hecha aquí.
     *
     * @param array<string,mixed>      $record
     * @param array<string,mixed>|null $localRow la fila de aquí a la que apunta la pareja guardada (null si no hay pareja o la fila ya no existe)
     *
     * @return string una de las constantes de TypeWriter: CREATED, UPDATED, UNCHANGED o CONFLICT
     */
    protected function decide(string $type, array $record, WriteContext $ctx, ?array $localRow): string
    {
        $mapped = $ctx->mapped($type, isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '');
        if ($mapped === null || $localRow === null) {
            return self::CREATED;
        }
        if ($mapped['source_hash'] === Neutral::hash($record)) {
            return self::UNCHANGED;
        }
        if (in_array($type, Types::CONFIG, true) || (bool) $ctx->option('overwrite_local', false)) {
            return self::UPDATED;
        }
        $edited = isset($localRow['updated_at']) && is_string($localRow['updated_at']) && $localRow['updated_at'] !== ''
            && $localRow['updated_at'] > $mapped['written_at'];

        return $edited ? self::CONFLICT : self::UPDATED;
    }

    /**
     * Casa un registro con su fila de aquí: por la pareja guardada y, si no hay, por lo natural (sku, código, nombre…).
     * Una fila casada por lo natural se trata como actualización (sin comprobar ediciones: no se sabe cuándo se escribió).
     *
     * @param array<string,mixed> $record
     * @param callable            $find    fn(int $localId): ?array  la fila de aquí por su identificador
     * @param callable|null       $natural fn(): ?array  una fila que ya existía y es la misma cosa, o null
     *
     * @return array{action:string,row:?array<string,mixed>,natural:bool}
     */
    protected function resolve(string $type, array $record, WriteContext $ctx, callable $find, ?callable $natural = null): array
    {
        $mapped = $ctx->mapped($type, isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '');
        $row = $mapped !== null ? $find($mapped['local_id']) : null;
        $action = $this->decide($type, $record, $ctx, $row);
        if ($action === self::CREATED && $natural !== null) {
            $found = $natural();
            if (is_array($found)) {
                return ['action' => self::UPDATED, 'row' => $found, 'natural' => true];
            }
        }

        return ['action' => $action, 'row' => $row, 'natural' => false];
    }

    /**
     * El resultado de un escritor.
     *
     * @return array{result:string,id:?int,reason:string}
     */
    protected function result(string $result, ?int $id = null, string $reason = ''): array
    {
        return ['result' => $result, 'id' => $id, 'reason' => $reason];
    }

    /** El resultado de un registro que choca con una edición hecha aquí. */
    protected function conflict(?int $id): array
    {
        return $this->result(self::CONFLICT, $id, 'It was edited here after it was brought and was left as it is');
    }

    /**
     * Envuelve la escritura de UN registro en una transacción: una excepción, o un resultado «skipped», lo deshacen todo.
     * En simulacro no hay transacción (no se escribe nada). Nunca lanza.
     *
     * @return array{result:string,id:?int,reason:string}
     */
    protected function safe(callable $fn, ?WriteContext $ctx = null): array
    {
        if ($ctx !== null && $ctx->dryRun()) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                return $this->failure($e);
            }
        }
        $outcome = null;
        $skipped = false;

        try {
            $this->app->db()->transaction(function () use ($fn, &$outcome, &$skipped): void {
                $outcome = $fn();
                if (is_array($outcome) && isset($outcome['result']) && $outcome['result'] === self::SKIPPED) {
                    $skipped = true;
                    throw new \DomainException('rollback');
                }
            });
        } catch (\Throwable $e) {
            if (!$skipped) {
                return $this->failure($e);
            }
        }

        return is_array($outcome) ? $outcome : $this->result(self::SKIPPED, null, 'The writer returned nothing');
    }

    /**
     * @return array{result:string,id:?int,reason:string}
     */
    private function failure(\Throwable $e): array
    {
        return $this->result(self::SKIPPED, null, 'The record could not be written: ' . Redactor::scrub($e->getMessage(), $this->secrets, 180));
    }

    // ------------------------------------------------------------------ textos

    /**
     * Los textos de un campo por idioma, solo en los idiomas del sitio. Si falta el principal se usa el primero que haya
     * (y se apunta); cada idioma de fuera se cuenta una sola vez por registro.
     *
     * @param array<mixed,mixed> $i18n [idioma => texto]
     *
     * @return array<string,string>
     */
    protected function texts(array $i18n, WriteContext $ctx): array
    {
        $site = $ctx->languages();
        $main = $site !== [] ? $site[0] : 'es';
        $out = [];
        $first = null;
        foreach ($i18n as $lang => $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            if ($first === null) {
                $first = (string) $lang;
            }
            $short = $this->siteLanguage((string) $lang, $ctx);
            if ($short !== null && !isset($out[$short])) {
                $out[$short] = $text;
            }
        }
        if (!isset($out[$main]) && $first !== null) {
            $out[$main] = (string) $i18n[$first];
            $this->noteOnce($ctx, 'main-language', 'There is no text in the main language: the one in «' . $first . '» was used');
        }

        return $out;
    }

    /**
     * El texto en el idioma principal del sitio (el primero que haya si falta), o ''.
     *
     * @param array<mixed,mixed> $i18n
     */
    protected function mainText(array $i18n, WriteContext $ctx): string
    {
        $texts = $this->texts($i18n, $ctx);
        $site = $ctx->languages();
        $main = $site !== [] ? $site[0] : 'es';

        return isset($texts[$main]) ? $texts[$main] : '';
    }

    /**
     * El idioma del origen como lo llama el sitio si el sitio lo tiene («pt-BR» → «pt»); null si no lo tiene (y se cuenta una sola
     * vez por registro en «sin equivalente»).
     */
    protected function siteLanguage(string $lang, WriteContext $ctx): ?string
    {
        $short = $this->shortLang($lang);
        if (in_array($short, $ctx->languages(), true)) {
            return $short;
        }
        if (!$this->quiet && !isset($this->seenLanguages[$short])) {
            $this->seenLanguages[$short] = true;
            $ctx->unmapped('language', $short, 1, 'The site does not have this language: its texts were not brought');
        }

        return null;
    }

    /** El HTML sin peligros (scripts, manejadores de eventos…). */
    protected function html(string $html): string
    {
        return HtmlSanitizer::clean($html);
    }

    /** «pt-BR» → «pt»; todo en minúsculas. */
    protected function shortLang(string $lang): string
    {
        return strtolower(substr(trim($lang), 0, 2));
    }

    /**
     * Una nota «note» por registro y por clave (varias llamadas del mismo registro no la repiten).
     */
    protected function noteOnce(WriteContext $ctx, string $key, string $text): void
    {
        if ($this->quiet || isset($this->noted[$key])) {
            return;
        }
        $this->noted[$key] = true;
        $ctx->note($this->type(), $this->currentId, 'note', $text);
    }

    // ------------------------------------------------------------------ precios

    /**
     * El importe como se escriben los precios AQUÍ (opción prices_include_tax_here): si el origen y el sitio coinciden, el mismo;
     * si no, con el tipo de la clase para el país de la tienda y con Money (nunca coma flotante).
     *
     * Si el origen guardaba el importe con más precisión que el céntimo ($micros, millonésimas), la conversión de impuestos sale de ahí y no
     * del céntimo ya redondeado: 82,644628 € + 21 % son 100,00 €, y desde 82,64 € saldrían 99,99 €. El redondeo es UNO, al final, sobre el
     * importe con impuestos. Todo con enteros: poner impuestos, divRound(micros × (10000 + tipo), 10^8); quitarlos, divRound(micros, 10000 + tipo).
     *
     * Con precios SIN impuestos aquí, una base en céntimos no siempre puede formar el importe con impuestos del origen (a 21 %, 100,00 € no
     * existe): closestNet() busca la base que da exacto o, si no la hay, la más cercana, y lo apunta.
     */
    protected function price(int $cents, bool $includesTax, ?int $taxClassId, WriteContext $ctx, ?int $micros = null): int
    {
        $here = (bool) $ctx->option('prices_include_tax_here', true);
        if (($includesTax && $here) || ($cents === 0 && ($micros === null || $micros === 0))) {
            return $cents;
        }
        $rate = $this->shopRate($taxClassId);
        if ($rate <= 0) {
            return $cents;
        }
        if (!$includesTax && $here) {
            // Sin impuestos en el origen, con ellos aquí: una sola conversión y un solo redondeo, sobre el importe con impuestos.
            if ($micros !== null && $micros >= 0) {
                return Money::divRound($micros * (Money::SCALE + $rate), 100000000);
            }

            return Money::add($cents, $rate)[1];
        }
        if ($includesTax) {
            // Con impuestos en el origen, sin ellos aquí: lo que paga el cliente es el importe del origen y la base tiene que dar ese importe.
            $net = $micros !== null && $micros >= 0 ? Money::divRound($micros, Money::SCALE + $rate) : Money::split($cents, $rate)[0];

            return $this->closestNet($cents, $rate, $net, $ctx);
        }
        // Sin impuestos en el origen y aquí: el céntimo, salvo que la precisión que se pierde cambie lo que paga el cliente.
        if ($micros === null || $micros < 0) {
            return $cents;
        }

        return $this->closestNet(Money::divRound($micros * (Money::SCALE + $rate), 100000000), $rate, $cents, $ctx);
    }

    /**
     * La base, en céntimos, que al ponerle el impuesto da el importe con impuestos que cobraba el origen. Se busca cerca de la que sale de
     * redondear; si ninguna lo da exacto (con una base en céntimos hay importes con impuestos que no existen: a 21 %, 100,00 € no se puede
     * formar) se deja la más cercana, y si hay empate la de siempre, y se apunta que el precio cambia un céntimo.
     */
    private function closestNet(int $gross, int $rate, int $around, WriteContext $ctx): int
    {
        $best = $around;
        $error = abs(Money::add($around, $rate)[1] - $gross);
        for ($step = 1; $step <= 2 && $error > 0; ++$step) {
            foreach ([-$step, $step] as $move) {
                $candidate = $around + $move;
                if ($candidate < 0) {
                    continue;
                }
                $distance = abs(Money::add($candidate, $rate)[1] - $gross);
                if ($distance < $error) {
                    $best = $candidate;
                    $error = $distance;
                }
            }
        }
        if ($error > 0) {
            $this->noteOnce($ctx, 'price-cent', 'A price cannot be written to the cent with prices without taxes: it differs by a cent from the source');
        }

        return $best;
    }

    /**
     * Las millonésimas de un importe, solo si son de fiar: un entero no negativo que es exactamente lo que redondea al céntimo que viaja con
     * ellas. Si no cuadran (un lector estropeado, un registro retocado a mano) se ignoran y manda el céntimo, que es lo que había antes.
     *
     * @param mixed $micros
     */
    protected function sureMicros($micros, int $cents): ?int
    {
        if (!is_int($micros) || $micros < 0 || $cents < 0 || Money::divRound($micros, Money::SCALE) !== $cents) {
            return null;
        }

        return $micros;
    }

    /**
     * El tipo (centésimas de punto) de una clase para el país de la tienda; 0 si no hay módulo de impuestos o no hay tipo.
     */
    protected function shopRate(?int $taxClassId): int
    {
        if (!$this->app->has('tax')) {
            return 0;
        }
        $tax = $this->app->service('tax');
        $rate = $tax->rate($taxClassId, $tax->baseDestination());

        return max(0, (int) $rate['rate']);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * ¿Hay una fila con esos valores en la tabla?
     *
     * @param array<string,mixed> $where
     */
    protected function exists(string $table, array $where): bool
    {
        return $this->app->db()->find($table, $where) !== null;
    }

    /** Ahora, en la forma de la base de datos. */
    protected function now(): string
    {
        return $this->app->clock()->sql();
    }

    /** «2026-11-01T00:00:00Z» → «2026-11-01 00:00:00»; null si no tiene esa forma. */
    protected function sqlDate($utc): ?string
    {
        if (!is_string($utc) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/', $utc, $m) !== 1) {
            return null;
        }

        return $m[1] . ' ' . $m[2];
    }

    /**
     * La región de un país tal como la guarda y la compara AIFEC: en España, los dos dígitos de la provincia («35»), no la letra del origen («GC»).
     */
    protected function region(string $country, string $region): string
    {
        $region = trim($region);
        if ($region === '' || strtoupper($country) !== 'ES' || ctype_digit($region)) {
            return $region;
        }
        $wanted = strtoupper($region);
        foreach (Spain::provinces() as $province) {
            if (strtoupper((string) $province['code']) === $wanted) {
                return (string) $province['prefix'];
            }
        }

        return $region;
    }

    /**
     * Un código corto y único: minúsculas, cifras y guiones, de $max letras como mucho. Si ya está en $taken, se le añade «-2», «-3»…
     *
     * @param string[] $taken códigos que ya existen
     */
    protected function code(string $text, string $fallback, int $max, array $taken): string
    {
        $base = Str::slug($text, $max);
        if ($base === '') {
            $base = $fallback;
        }
        $base = substr($base, 0, $max);
        $code = $base;
        for ($n = 2; in_array($code, $taken, true); ++$n) {
            $suffix = '-' . $n;
            $code = substr($base, 0, $max - strlen($suffix)) . $suffix;
        }

        return $code;
    }

    /**
     * La condición SQL «esta columna vale este texto, sin mirar mayúsculas» y sus parámetros. Compara el texto tal cual o con las letras ASCII
     * en minúsculas: el LOWER de cada motor trata distinto lo que no es ASCII, y strtolower() de PHP 7.4 depende de la configuración regional
     * y estropea el UTF-8. Así el resultado es el mismo en todos los motores y en todas las versiones de PHP.
     *
     * @param array<int,mixed> $params se le añaden los dos valores
     */
    protected function sameText(string $column, string $value, array &$params): string
    {
        $value = trim($value);
        $params[] = $value;
        $params[] = strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        return '(' . $column . ' = ? OR LOWER(' . $column . ') = ?)';
    }

    /**
     * Cuántas parejas de un tipo apuntan a filas que siguen existiendo en $table (la cifra de «lo traído» para la comprobación final).
     */
    protected function mappedCount(string $type, string $table): int
    {
        $db = $this->app->db();

        return (int) $db->value(
            'SELECT COUNT(*) FROM {import_map} m JOIN {' . $table . '} t ON t.id = m.local_id WHERE m.type = ?',
            [$type]
        );
    }

    /**
     * ¿Esta fila de aquí ya es la pareja de OTRO registro del origen? (Dos registros del origen no casan con la misma fila.)
     */
    protected function claimed(string $type, int $localId, string $sourceId): bool
    {
        return (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM {import_map} WHERE type = ? AND local_id = ? AND source_id <> ?',
            [$type, $localId, $sourceId]
        ) > 0;
    }

    /**
     * La clave con la que se apunta en import_map un valor de atributo (el identificador del valor solo es único dentro de su atributo).
     */
    public static function valueKey(string $attributeId, string $valueId): string
    {
        $key = $attributeId . '/' . $valueId;

        return strlen($key) <= 64 ? $key : 'h' . sha1($key);
    }

    /** Un texto de una sola línea y de $max letras como mucho. */
    protected function cut(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
    }
}
