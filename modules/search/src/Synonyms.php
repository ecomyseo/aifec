<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Kernel\App;

/**
 * Los sinónimos del comerciante. Dos clases:
 *   - equal: todos los términos significan lo mismo («sofá, sillón»): buscar cualquiera encuentra los demás;
 *   - oneway: «de → a» en un solo sentido: quien busca «source» también encuentra «terms», pero no al revés.
 *
 * Un término puede ser una frase («sofá cama»). Se aplican sobre las raíces de la consulta: cada palabra (o frase) afectada
 * pasa a ser un grupo con varias formas de escribirlo, y un documento cumple el grupo si tiene cualquiera de ellas entera.
 *
 * Las reglas de cada idioma se guardan en la caché con la etiqueta «search»: al guardar un sinónimo en el panel se vacía.
 */
final class Synonyms
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Las reglas que valen para un idioma (las suyas y las de «todos los idiomas»).
     *
     * @return array<int,array{mode:string,source:string,terms:string[]}>
     */
    public function rules(string $lang): array
    {
        $cache = $this->app->cache();
        $key = 'search:syn:' . $lang;
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $rules = [];
        $db = $this->app->db();
        if ($db->tableExists('synonyms')) {
            foreach ($db->select("SELECT mode, source, terms FROM {synonyms} WHERE lang = ? OR lang = '' ORDER BY id", [$lang]) as $row) {
                $terms = self::split((string) $row['terms']);
                $mode = (string) $row['mode'] === 'oneway' ? 'oneway' : 'equal';
                $source = isset($row['source']) && $row['source'] !== null ? trim((string) $row['source']) : '';
                if ($terms === [] || ($mode === 'oneway' && $source === '') || ($mode === 'equal' && count($terms) < 2)) {
                    continue;
                }
                $rules[] = ['mode' => $mode, 'source' => $source, 'terms' => $terms];
            }
        }
        // Los de serie del idioma (14/11/2026: «colonia» no encontraba ningún perfume), detrás de los de la tienda; se apagan en los
        // ajustes del buscador.
        if ($this->app->settings()->bool('search.default_synonyms', true) && isset(self::DEFAULTS[$lang])) {
            foreach (self::DEFAULTS[$lang] as $terms) {
                $rules[] = ['mode' => 'equal', 'source' => '', 'terms' => $terms];
            }
        }
        $cache->set($key, $rules, 3600, ['search']);

        return $rules;
    }

    /** Palabras que en una tienda quieren decir lo mismo, por idioma. Solo equivalencias claras: nada que cambie de producto. */
    private const DEFAULTS = [
        'es' => [
            ['colonia', 'perfume', 'fragancia', 'eau de toilette', 'eau de parfum'],
            ['frigorífico', 'nevera', 'frigo'],
            ['móvil', 'smartphone', 'teléfono móvil'],
            ['portátil', 'ordenador portátil'],
            ['gafas de sol', 'gafas solares'],
            ['chubasquero', 'impermeable', 'chaqueta de lluvia'],
            ['cazadora', 'chaqueta'],
            ['zapatillas de casa', 'zapatillas de estar por casa', 'pantuflas', 'zapatillas de andar por casa'],
            ['deportivas', 'zapatillas deportivas', 'tenis', 'bambas'],
            ['sudadera', 'jersey de chándal'],
            ['vaqueros', 'tejanos', 'jeans'],
            ['cesta', 'canasta'],
            ['detergente', 'jabón para la ropa'],
            ['soporte', 'asistencia técnica', 'ayuda técnica', 'servicio técnico'],
            ['aviso', 'avisos', 'notificación', 'notificaciones', 'alerta', 'alertas'],
        ],
    ];

    /**
     * Parte una lista escrita por una persona: por comas, puntos y comas o saltos de línea.
     *
     * @return string[]
     */
    public static function split(string $text): array
    {
        $parts = preg_split('/[,;\n\r]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[$part] = $part;
            }
        }

        return array_values($out);
    }

    /**
     * Añade a los grupos de la consulta las formas equivalentes que digan las reglas.
     *
     * Un grupo es ['alts' => [[raíz, raíz…], …], 'word' => palabra escrita, 'chunk' => lo escrito, 'typo' => bool]. La forma
     * escrita por la persona es la primera de 'alts'; las demás son sinónimos. Una frase que ocupa varias palabras de la
     * consulta las funde en un solo grupo.
     *
     * @param array<int,array<string,mixed>> $groups
     *
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $groups, Analyzer $analyzer, string $lang): array
    {
        foreach ($this->rules($lang) as $rule) {
            $oneway = $rule['mode'] === 'oneway';
            $triggers = [];
            foreach ($oneway ? [$rule['source']] : $rule['terms'] as $phrase) {
                $triggers[] = $analyzer->tokens($phrase);
            }
            $others = [];
            foreach ($oneway ? $rule['terms'] : [] as $phrase) {
                $others[] = $analyzer->tokens($phrase);
            }
            foreach ($triggers as $i => $tokens) {
                if ($tokens === []) {
                    continue;
                }
                $span = self::find($groups, $tokens);
                if ($span === null) {
                    continue;
                }
                $extra = [];
                if ($oneway) {
                    foreach ($others as $alt) {
                        if ($alt !== []) {
                            $extra[] = $alt;
                        }
                    }
                } else {
                    foreach ($triggers as $j => $alt) {
                        if ($j !== $i && $alt !== []) {
                            $extra[] = $alt;
                        }
                    }
                }
                if ($extra === []) {
                    continue;
                }
                list($from, $to) = $span;
                if ($from === $to) {
                    $groups[$from]['alts'] = self::merge($groups[$from]['alts'], $extra);
                    $groups[$from]['typo'] = false;
                } else {
                    $flat = [];
                    $words = [];
                    for ($k = $from; $k <= $to; ++$k) {
                        $flat = array_merge($flat, $groups[$k]['alts'][0]);
                        $words[] = $groups[$k]['word'];
                    }
                    $merged = ['alts' => self::merge([$flat], $extra), 'word' => implode(' ', $words), 'chunk' => implode(' ', $words), 'typo' => false];
                    array_splice($groups, $from, $to - $from + 1, [$merged]);
                }
                break;
            }
        }

        return array_values($groups);
    }

    /**
     * Dónde empieza y acaba (por grupos) la frase en la consulta, comparando con la forma escrita; null si no está.
     *
     * @param array<int,array<string,mixed>> $groups
     * @param string[]                       $tokens
     *
     * @return array{0:int,1:int}|null
     */
    private static function find(array $groups, array $tokens): ?array
    {
        $count = count($groups);
        $length = count($tokens);
        for ($start = 0; $start < $count; ++$start) {
            $flat = [];
            for ($end = $start; $end < $count; ++$end) {
                $flat = array_merge($flat, $groups[$end]['alts'][0]);
                if (count($flat) >= $length) {
                    if ($flat === $tokens) {
                        return [$start, $end];
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int,string[]> $alts
     * @param array<int,string[]> $extra
     *
     * @return array<int,string[]>
     */
    private static function merge(array $alts, array $extra): array
    {
        $seen = [];
        foreach ($alts as $alt) {
            $seen[implode(' ', $alt)] = true;
        }
        foreach ($extra as $alt) {
            $key = implode(' ', $alt);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $alts[] = $alt;
            }
        }

        return $alts;
    }
}
