<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations\Scopes;

use Aifec\Kernel\App;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\Scope;

/**
 * Las etiquetas de los elementos de los menús. Se leen y se guardan por el servicio público del módulo de contenidos
 * (`cms->menus()->editable()` y `save()`, lo mismo que hace su editor). Lo que propone la IA espera en translation_proposals con
 * el texto de partida al lado: al aprobarlo, si el menú ha cambiado y esa etiqueta ya no dice lo mismo, la propuesta se descarta.
 *
 * Un elemento sin etiqueta propia en el idioma principal enseña el nombre de lo que enlaza (una página, una categoría): eso se
 * traduce con su entidad, no aquí.
 */
final class MenusScope implements Scope
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return 'menus';
    }

    public function label(): string
    {
        return t('Menus');
    }

    public function describe(): string
    {
        return t('Labels written by hand in the menus of the shop');
    }

    public function entities(): array
    {
        return ['menu'];
    }

    public function sourceLang(): string
    {
        return $this->app->defaultLang();
    }

    public function brief(): string
    {
        return 'Labels of the navigation menus of an online shop: one to four words each.';
    }

    public function blocked(string $lang): string
    {
        return $lang === $this->app->defaultLang() ? t('This is the main language: the other languages are translated from it') : '';
    }

    public function coverage(string $lang): array
    {
        $out = ['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0];
        if ($lang === $this->app->defaultLang()) {
            return $out;
        }
        $pending = (new Proposals($this->app))->pending($lang, 'menus');
        foreach ($this->items($lang) as $key => $item) {
            ++$out['total'];
            if ($item['target'] !== '') {
                ++$out['translated'];
            } elseif (isset($pending[$key])) {
                ++$out['proposed'];
            } else {
                ++$out['missing'];
            }
        }

        return $out;
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        $pending = (new Proposals($this->app))->pending($lang, 'menus');
        $units = [];
        $chars = 0;
        $cursor = $after;
        foreach ($this->items($lang) as $key => $item) {
            if ($after !== '' && strcmp($key, $after) <= 0) {
                continue;
            }
            if ($item['target'] !== '' || isset($pending[$key])) {
                $cursor = $key;
                continue;
            }
            if ($units !== [] && (count($units) >= $maxUnits || $chars + strlen($item['source']) > $maxChars)) {
                return ['units' => $units, 'cursor' => $cursor, 'done' => false];
            }
            $units[] = ['key' => $key, 'text' => $item['source'], 'format' => 'text'];
            $chars += strlen($item['source']);
            $cursor = $key;
        }

        return ['units' => $units, 'cursor' => $cursor, 'done' => true];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        if ($publish) {
            $sources = [];
            foreach ($texts as $key => $text) {
                $sources[$key] = isset($units[$key]['text']) ? (string) $units[$key]['text'] : '';
            }
            $applied = $this->apply($lang, $texts, $sources);

            return array_values(array_diff(array_keys($texts), $applied));
        }
        $proposals = new Proposals($this->app);
        foreach ($texts as $key => $text) {
            $proposals->put($lang, 'menus', (string) $key, isset($units[$key]['text']) ? (string) $units[$key]['text'] : '', $text, $jobId);
        }

        return [];
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        $proposals = new Proposals($this->app);
        $pending = $proposals->pending($lang, 'menus');
        $texts = [];
        $sources = [];
        foreach ($pending as $key => $row) {
            $texts[(string) $key] = $row['text'];
            $sources[(string) $key] = $row['source'];
        }
        $applied = $texts === [] ? [] : $this->apply($lang, $texts, $sources);
        $proposals->remove($lang, 'menus', array_keys($texts));

        return count($applied);
    }

    public function approvalNeedsAi(): bool
    {
        return false;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/translations/' . $lang . '/menus');
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        $pending = (new Proposals($this->app))->pending($lang, 'menus');
        $names = $this->menuNames();
        $list = [];
        foreach ($this->items($lang) as $key => $item) {
            $code = substr($key, 0, (int) strrpos($key, ':'));
            $status = $item['target'] !== '' ? 'ok' : (isset($pending[$key]) ? 'proposed' : 'missing');
            $list[] = [
                'label' => (isset($names[$code]) ? $names[$code] : $code) . ' · ' . $item['source'],
                'url' => $this->app->url()->plain('/admin/cms/menus/' . $code),
                'fields' => ['label' => $status],
            ];
        }

        return ['fields' => ['label' => t('Label')], 'rows' => array_slice($list, max(0, ($page - 1) * $perPage), $perPage), 'total' => count($list)];
    }

    public function tokensPerUnit(): array
    {
        return [8, 10];
    }

    // ------------------------------------------------------------------ interior

    /**
     * Los elementos con etiqueta propia en el idioma principal: «código:posición» => [source, target], ordenados por clave.
     *
     * @return array<string,array{source:string,target:string}>
     */
    private function items(string $lang): array
    {
        $default = $this->app->defaultLang();
        $menus = $this->app->service('cms')->menus();
        $out = [];
        foreach (array_keys($this->menuNames()) as $code) {
            foreach ($menus->editable((string) $code) as $position => $item) {
                $labels = isset($item['labels']) && is_array($item['labels']) ? $item['labels'] : [];
                $source = isset($labels[$default]) && is_string($labels[$default]) ? trim($labels[$default]) : '';
                if ($source === '') {
                    continue;
                }
                $target = isset($labels[$lang]) && is_string($labels[$lang]) ? trim($labels[$lang]) : '';
                $out[$code . ':' . sprintf('%04d', (int) $position)] = ['source' => $source, 'target' => $target];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return array<string,string> código => nombre */
    private function menuNames(): array
    {
        $out = [];
        foreach ($this->app->db()->select('SELECT code, name FROM {menus} ORDER BY code') as $row) {
            $out[(string) $row['code']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Escribe etiquetas en los menús, por su servicio, una vez por menú. Solo si la etiqueta del idioma principal sigue diciendo lo
     * mismo que cuando se tradujo. Devuelve las claves aplicadas.
     *
     * @param array<string,string> $texts   «código:posición» => traducción
     * @param array<string,string> $sources «código:posición» => texto de partida
     *
     * @return string[]
     */
    private function apply(string $lang, array $texts, array $sources): array
    {
        $default = $this->app->defaultLang();
        $menus = $this->app->service('cms')->menus();
        $names = $this->menuNames();
        $byMenu = [];
        foreach ($texts as $key => $text) {
            $pos = strrpos((string) $key, ':');
            if ($pos === false) {
                continue;
            }
            $byMenu[substr((string) $key, 0, $pos)][(int) substr((string) $key, $pos + 1)] = (string) $key;
        }
        $applied = [];
        foreach ($byMenu as $code => $positions) {
            if (!isset($names[$code])) {
                continue;
            }
            $items = $menus->editable((string) $code);
            $changed = false;
            foreach ($positions as $position => $key) {
                if (!isset($items[$position])) {
                    continue;
                }
                $labels = isset($items[$position]['labels']) && is_array($items[$position]['labels']) ? $items[$position]['labels'] : [];
                $now = isset($labels[$default]) && is_string($labels[$default]) ? trim($labels[$default]) : '';
                if ($now === '' || $now !== (isset($sources[$key]) ? $sources[$key] : '')) {
                    continue;
                }
                $labels[$lang] = $texts[$key];
                $items[$position]['labels'] = $labels;
                $applied[] = $key;
                $changed = true;
            }
            if ($changed) {
                $menus->save((string) $code, $names[$code], $items);
            }
        }

        return $applied;
    }
}
