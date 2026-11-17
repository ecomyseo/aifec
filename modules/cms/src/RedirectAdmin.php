<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Kernel\App;
use Aifec\Text\UrlFilter;

/**
 * Redirecciones manuales en el panel (recurso «redirects»; ver Aifec\Admin\ResourceController y Aifec\Admin\Form) y la
 * pantalla de direcciones no encontradas, desde donde se crea una redirección con un clic.
 *
 * El origen se guarda como ruta sin barra inicial ni final (puede acabar en `*`); el destino, una dirección completa o una
 * ruta del sitio. Las que cambian de dirección una página o una entrada no hacen falta aquí: lo hace solo el sistema.
 */
final class RedirectAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'source' => ['label' => 'From', 'link' => true, 'sort' => true],
                'target' => ['label' => 'To', 'limit' => 70],
                'code' => ['label' => 'Type', 'type' => 'badge', 'map' => ['301' => ['Permanent (301)', 'ok'], '302' => ['Temporary (302)', 'warn']]],
                'hits' => ['label' => 'Times used', 'type' => 'int', 'sort' => true],
                'active' => ['label' => 'Active', 'type' => 'bool', 'sort' => true],
            ],
            'search' => ['source', 'target'],
            'filters' => ['active' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Paused']]],
            'sort' => ['hits', 'desc'],
            'per_page' => 50,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        return ['fields' => [
            'source' => ['label' => 'From', 'rules' => 'required|max:191', 'placeholder' => 'old/address',
                'hint' => 'The address that no longer exists, without the domain. End it with * to include everything under it.'],
            'target' => ['label' => 'To', 'rules' => 'required|max:1000', 'placeholder' => '/new/address',
                'hint' => 'A full address (https://…) or an address of this shop. With * in the origin, a * here receives what the origin matched.'],
            'code' => ['type' => 'select', 'label' => 'Type', 'options' => ['301' => 'Permanent (301)', '302' => 'Temporary (302)'], 'default' => '301', 'rules' => 'required', 'width' => 'md',
                'hint' => 'Use permanent when the old address will never come back.'],
            'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
            'note' => ['label' => 'Note', 'rules' => 'max:191', 'hint' => 'Only you see it.'],
            'hits' => ['type' => 'readonly', 'label' => 'Times used'],
            'created_at' => ['type' => 'hidden'],
        ]];
    }

    /**
     * Normaliza el origen y el destino, comprueba que no se repitan ni den un bucle, y fija la fecha de alta.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $errors = [];
        $source = Redirects::normalize(isset($values['source']) ? (string) $values['source'] : '');
        if ($source === '') {
            $errors['source'] = t('Write the address that has to be redirected');
        } elseif (strpos(substr($source, 0, -1), '*') !== false) {
            $errors['source'] = t('The * can only go at the end');
        } else {
            $same = $app->db()->value('SELECT id FROM {redirects} WHERE source = ? AND id <> ?', [$source, $current !== null ? (int) $current['id'] : 0]);
            if ($same !== null) {
                $errors['source'] = t('There is already a redirection from this address');
            }
        }
        $target = self::cleanTarget(isset($values['target']) ? (string) $values['target'] : '');
        if ($target === '') {
            $errors['target'] = t('Write a full address or an address of this shop');
        } elseif ($source !== '' && preg_match('#^https?://#i', $target) !== 1 && Redirects::normalize($target) === $source) {
            $errors['target'] = t('The destination cannot be the same address');
        }
        if ($errors !== []) {
            return ['_errors' => $errors];
        }
        $values['source'] = $source;
        $values['target'] = $target;
        $values['code'] = (string) $values['code'] === '302' ? 302 : 301;
        $values['created_at'] = $current !== null ? (string) $current['created_at'] : $app->clock()->sql();

        return $values;
    }

    /**
     * Destino listo para guardar: una dirección completa (http/https) o una ruta del sitio con barra delante; vacío si no vale.
     */
    public static function cleanTarget(string $target): string
    {
        $safe = UrlFilter::safe($target);
        if ($safe === null) {
            return '';
        }
        if (preg_match('#^https?://#i', $safe) === 1) {
            return $safe;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $safe) === 1 || strpos($safe, '#') === 0 || strpos($safe, '//') === 0) {
            return '';
        }

        return '/' . ltrim($safe, '/');
    }

    /**
     * Crea una redirección desde una dirección apuntada en «no encontradas» y la quita de esa lista. Devuelve el motivo si no
     * se puede, o null si se creó.
     */
    public static function createFromNotFound(App $app, string $path, string $target, int $code): ?string
    {
        $values = self::beforeSave(['source' => $path, 'target' => $target, 'code' => (string) $code], null, $app);
        if (isset($values['_errors'])) {
            return (string) reset($values['_errors']);
        }
        $db = $app->db();
        $db->insert('redirects', [
            'source' => $values['source'],
            'target' => $values['target'],
            'code' => $values['code'],
            'hits' => 0,
            'active' => 1,
            'created_at' => $values['created_at'],
        ]);
        $db->delete('not_found', ['path' => Redirects::normalize($path)]);

        return null;
    }
}
