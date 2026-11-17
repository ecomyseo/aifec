<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * Entiende una orden escrita en castellano o en inglés SIN IA: pregunta a cada acción registrada que sepa leer texto
 * («understand») y se queda con las lecturas que valen, la mejor primero.
 *
 * Cada acción devuelve los datos que encontró ya en su forma máquina y una puntuación de 0 a 100; aquí solo se comprueba que
 * los datos son de la acción y están bien tipados. No cambia nada ni toca la base de datos más que para buscar nombres.
 */
final class Understand
{
    /** Por debajo de esta puntuación una lectura no cuenta. */
    public const MIN_SCORE = 60;

    /** Dos lecturas a menos de estos puntos se ofrecen las dos. */
    public const CLOSE = 10;

    /** El texto no pasa de esto. */
    public const TEXT_MAX = 1000;

    /** @var App */
    private $app;

    /** @var Actions */
    private $actions;

    public function __construct(App $app, Actions $actions)
    {
        $this->app = $app;
        $this->actions = $actions;
    }

    /**
     * Las lecturas del texto, la mejor primero (como mucho tres, y solo las que se acercan a la mejor).
     *
     * @return array<int,array{action:string,args:array<string,mixed>,score:int}>
     */
    public function candidates(string $text): array
    {
        $raw = trim(mb_substr(mb_scrub($text, 'UTF-8'), 0, self::TEXT_MAX, 'UTF-8'));
        if ($raw === '') {
            return [];
        }
        $norm = Reader::norm($raw);
        $found = [];
        foreach ($this->actions->all() as $id => $def) {
            if ($def['understand'] === null) {
                continue;
            }
            try {
                $read = call_user_func($def['understand'], $this->app, $raw, $norm);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'copilot understand ' . $id);
                continue;
            }
            if (!is_array($read) || !isset($read['score']) || !is_numeric($read['score']) || (int) $read['score'] < self::MIN_SCORE) {
                continue;
            }
            $args = Params::clean($def['params'], isset($read['args']) && is_array($read['args']) ? $read['args'] : []);
            $found[] = ['action' => (string) $id, 'args' => $args, 'score' => (int) $read['score']];
        }
        if ($found === []) {
            return [];
        }
        usort($found, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['action'], $b['action']);
        });
        $best = $found[0]['score'];

        return array_slice(array_values(array_filter($found, static function (array $c) use ($best): bool {
            return $best - $c['score'] <= self::CLOSE;
        })), 0, 3);
    }
}
