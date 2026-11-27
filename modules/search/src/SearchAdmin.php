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
 * El recurso «synonyms» del panel: listado, formulario y validación al guardar.
 *
 * Un sinónimo «equal» dice que varias palabras significan lo mismo; uno «oneway» dice que quien busca «source» también quiere
 * encontrar «terms» (pero no al revés). Con ?source=… en el alta, el formulario sale ya relleno como «oneway»: es lo que hace
 * el botón «crear sinónimo» de las búsquedas sin resultado.
 */
final class SearchAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'select' => '*',
            'search' => ['source', 'terms'],
            'filters' => ['mode' => ['label' => 'Type', 'options' => ['equal' => 'Both ways', 'oneway' => 'One way']]],
            'columns' => [
                'mode' => ['label' => 'Type', 'type' => 'badge', 'sort' => true, 'map' => ['equal' => ['Both ways', 'info'], 'oneway' => ['One way', 'info']]],
                'source' => ['label' => 'When someone searches', 'link' => true, 'sort' => true, 'limit' => 60],
                'terms' => ['label' => 'Also find', 'limit' => 90],
                'lang' => ['label' => 'Language', 'hide_sm' => true],
            ],
            'sort' => ['id', 'desc'],
            'per_page' => 25,
            'export' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $source = ['label' => 'When someone searches', 'rules' => 'nullable|max:120', 'show_if' => ['mode' => 'oneway'], 'width' => 'md',
            'hint' => 'The word or phrase the customer types.'];
        $mode = ['type' => 'select', 'label' => 'Type', 'default' => 'equal', 'rules' => 'required|in:equal,oneway',
            'options' => ['equal' => 'Both ways', 'oneway' => 'One way'],
            'hint' => 'Both ways: all the words mean the same. One way: searching the first one also finds the others, but not the other way round.'];
        if ($id === null && $app->request()->str('source') !== '') {
            $source['default'] = mb_substr(trim($app->request()->str('source')), 0, 120, 'UTF-8');
            $mode['default'] = 'oneway';
        }
        $languages = [];
        foreach ($app->languages() as $lang) {
            $languages[$lang] = strtoupper($lang);
        }

        return ['fields' => [
            'mode' => $mode,
            'sector' => ['type' => 'select', 'label' => 'Shop sector', 'options' => Synonyms::SECTORS, 'rules' => 'nullable|in:beauty,fashion,food,hardware'],
            'source' => $source,
            'terms' => ['type' => 'textarea', 'label' => 'Also find', 'rules' => 'required|max:1000',
                'default' => $id === null ? mb_substr($app->request()->str('terms'), 0, 1000, 'UTF-8') : '',
                'hint' => 'Separated by commas. Both ways: write every word that means the same.'],
            'lang' => ['type' => 'select', 'label' => 'Language', 'options' => $languages, 'default' => $id === null && isset($languages[$app->request()->str('lang')]) ? $app->request()->str('lang') : '', 'empty' => 'All languages', 'rules' => 'nullable', 'translate' => false, 'width' => 'sm'],
        ]];
    }

    /**
     * Deja los valores limpios y comprueba que la regla tenga sentido.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app)
    {
        $mode = isset($values['mode']) && $values['mode'] === 'oneway' ? 'oneway' : 'equal';
        $terms = Synonyms::split(isset($values['terms']) ? (string) $values['terms'] : '');
        $source = isset($values['source']) ? trim((string) $values['source']) : '';
        $errors = [];
        if ($mode === 'oneway' && $source === '') {
            $errors['source'] = t('Write the word that customers type');
        }
        if ($terms === []) {
            $errors['terms'] = t('Write at least one word');
        } elseif ($mode === 'equal' && count($terms) < 2) {
            $errors['terms'] = t('Write at least two words that mean the same');
        }
        if ($errors !== []) {
            return ['_errors' => $errors];
        }
        $values['sector'] = (string) ($values['sector'] ?? $current['sector'] ?? '');
        if (!array_key_exists($values['sector'], Synonyms::SECTORS)) { return ['_errors' => ['sector' => t('Invalid value')]]; }
        $values['mode'] = $mode;
        $values['source'] = $mode === 'oneway' ? $source : null;
        $values['terms'] = implode(', ', $terms);
        $values['lang'] = isset($values['lang']) && is_string($values['lang']) ? $values['lang'] : '';

        return $values;
    }
}
