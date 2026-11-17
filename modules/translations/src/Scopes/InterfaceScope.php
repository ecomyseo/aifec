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
use Aifec\Modules\Translations\Check;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\Scope;
use Aifec\Modules\Translations\SiteCatalog;
use Aifec\Modules\Translations\Strings;

/**
 * Los textos de la interfaz de un área (la tienda o el panel). El original es la cadena inglesa del código; la traducción es la
 * del catálogo del sitio si la hay y, si no, la del catálogo del código. Lo que propone la IA espera en translation_proposals y
 * solo pasa al catálogo del sitio al aprobarlo (o directamente, con «publicar sin revisar»).
 */
final class InterfaceScope implements Scope
{
    private App $app;
    private string $area;

    public function __construct(App $app, string $area)
    {
        $this->app = $app;
        $this->area = $area === Strings::PANEL ? Strings::PANEL : Strings::SHOP;
    }

    public function code(): string
    {
        return $this->area;
    }

    public function label(): string
    {
        return $this->area === Strings::PANEL ? t('Interface of the panel') : t('Interface of the shop');
    }

    public function describe(): string
    {
        return $this->area === Strings::PANEL
            ? t('Menus, buttons and messages of the panel, for the team')
            : t('Buttons, labels and messages that customers see');
    }

    public function entities(): array
    {
        return ['interface'];
    }

    public function sourceLang(): string
    {
        return 'en';
    }

    public function brief(): string
    {
        return $this->area === Strings::PANEL
            ? 'Short interface texts of the administration panel of an online shop: menus, buttons, labels and messages for the staff.'
            : 'Short interface texts of an online shop: buttons, labels and messages that customers see.';
    }

    public function blocked(string $lang): string
    {
        return $lang === 'en' ? t('The interface is written in English: there is nothing to translate') : '';
    }

    public function coverage(string $lang): array
    {
        $strings = Strings::inArea($this->app, $this->area);
        $total = count($strings);
        if ($lang === 'en') {
            return ['total' => $total, 'translated' => $total, 'proposed' => 0, 'missing' => 0];
        }
        $catalog = $this->effective($lang);
        $pending = (new Proposals($this->app))->pending($lang, $this->area);
        $translated = 0;
        $proposed = 0;
        foreach ($strings as $key => $info) {
            $key = (string) $key;
            if (isset($catalog[$key])) {
                ++$translated;
            } elseif (isset($pending[$key])) {
                ++$proposed;
            }
        }

        return ['total' => $total, 'translated' => $translated, 'proposed' => $proposed, 'missing' => max(0, $total - $translated - $proposed)];
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        $catalog = $this->effective($lang);
        $pending = (new Proposals($this->app))->pending($lang, $this->area);
        $units = [];
        $chars = 0;
        $cursor = $after;
        $done = true;
        foreach (Strings::inArea($this->app, $this->area) as $key => $info) {
            $key = (string) $key;
            if ($after !== '' && strcmp($key, $after) <= 0) {
                continue;
            }
            if (isset($catalog[$key]) || isset($pending[$key])) {
                $cursor = $key;
                continue;
            }
            $length = strlen($key);
            if ($units !== [] && (count($units) >= $maxUnits || $chars + $length > $maxChars)) {
                $done = false;
                break;
            }
            $units[] = ['key' => $key, 'text' => $key, 'format' => strpos($key, '|') !== false ? 'plural' : (Check::tags($key) !== [] ? 'html' : 'text')];
            $chars += $length;
            $cursor = $key;
        }

        return ['units' => $units, 'cursor' => $cursor, 'done' => $done];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        if ($publish) {
            (new SiteCatalog($this->app))->update($lang, $texts);
            (new Proposals($this->app))->remove($lang, $this->area, array_keys($texts));

            return [];
        }
        $proposals = new Proposals($this->app);
        foreach ($texts as $key => $text) {
            $proposals->put($lang, $this->area, (string) $key, (string) $key, $text, $jobId);
        }

        return [];
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        $proposals = new Proposals($this->app);
        $pending = $proposals->pending($lang, $this->area);
        $strings = Strings::inArea($this->app, $this->area);
        $approve = [];
        $drop = [];
        foreach ($pending as $key => $row) {
            $key = (string) $key;
            // Se vuelve a comprobar al aprobar: una cadena que el código ya no usa, o una propuesta que no vale, no se publica.
            if (!isset($strings[$key]) || Check::problem($key, $row['text']) !== null) {
                $drop[] = $key;
                continue;
            }
            $approve[$key] = $row['text'];
        }
        if ($approve !== []) {
            (new SiteCatalog($this->app))->update($lang, $approve);
        }
        $proposals->remove($lang, $this->area, array_merge(array_keys($approve), $drop));

        return count($approve);
    }

    public function approvalNeedsAi(): bool
    {
        return false;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/translations/' . $lang . '/' . $this->area);
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        return null;
    }

    public function tokensPerUnit(): array
    {
        return [14, 16];
    }

    /**
     * Lo que ya está traducido en ese idioma: el catálogo del código con el del sitio por encima.
     *
     * @return array<string,string>
     */
    private function effective(string $lang): array
    {
        return (new SiteCatalog($this->app))->all($lang) + Strings::codeCatalog($this->app, $lang);
    }
}
