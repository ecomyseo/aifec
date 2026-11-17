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
use Aifec\Kernel\Clock;

/**
 * Lo que comparten el panel de páginas y el de entradas: estados, regla de quién puede publicar, plantillas del tema, la
 * columna derecha (vista previa y versiones) y el cierre de cada guardado (versión, caché y gancho `content.saved`).
 *
 * Quién publica: un guardado que deja un contenido `published` o `scheduled` (nuevo, o con otro estado o otra fecha que antes)
 * exige el permiso `content.publish`; sin él, el panel contesta 403 y no guarda nada. Con solo `content.edit` se escribe y se
 * guarda como borrador.
 */
final class ContentAdmin
{
    /**
     * @return array<string,string>
     */
    public static function statusOptions(): array
    {
        return ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'];
    }

    /**
     * Para la columna de estado de un listado.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function statusMap(): array
    {
        return ['draft' => ['Draft', 'warn'], 'scheduled' => ['Scheduled', 'info'], 'published' => ['Published', 'ok']];
    }

    /**
     * Plantillas de página que ofrece el tema (`theme.json` → `templates`: código → nombre) y las tres de serie.
     *
     * @return array<string,string>
     */
    public static function templates(App $app): array
    {
        $list = ['default' => t('Default'), 'wide' => t('Wide'), 'landing' => t('Landing page')];
        $theme = $app->theme();
        foreach (isset($theme['templates']) && is_array($theme['templates']) ? $theme['templates'] : [] as $code => $label) {
            if (is_string($code) && preg_match('/^[a-z0-9_-]{1,40}$/', $code) === 1) {
                $name = is_array($label) && isset($label['label']) ? $label['label'] : $label;
                $list[$code] = is_string($name) && $name !== '' ? $name : $code;
            }
        }

        return $list;
    }

    /** Prefijo de las direcciones de las entradas. */
    public static function prefix(string $kind): string
    {
        if (!App::hasCurrent()) {
            return $kind === 'category' ? 'blog/categoria' : ($kind === 'tag' ? 'blog/etiqueta' : 'blog');
        }

        return (new Cms(App::current()))->prefix($kind);
    }

    /**
     * La regla común de estado y fecha. Devuelve los valores (con la fecha de publicación rellena si hace falta) o
     * `['_errors' => […]]`. Lanza 403 si quien guarda no puede publicar.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function check(array $values, ?array $current, App $app): array
    {
        $status = isset($values['status']) ? (string) $values['status'] : 'draft';
        $at = isset($values['published_at']) && is_string($values['published_at']) && $values['published_at'] !== '' ? $values['published_at'] : null;
        $was = $current !== null ? (string) $current['status'] : null;
        $wasAt = $current !== null && isset($current['published_at']) && $current['published_at'] !== null ? (string) $current['published_at'] : null;

        if ($status === 'scheduled' && $at === null) {
            return ['_errors' => ['published_at' => t('Choose the date and time when it will be published')]];
        }
        if ($status === 'published' && $at === null) {
            $at = $was === 'published' && $wasAt !== null ? $wasAt : $app->clock()->sql();
        }
        $values['published_at'] = $at;

        $live = $status === 'published' || $status === 'scheduled';
        if ($live && ($current === null || $was !== $status || $wasAt !== $at)) {
            $app->auth()->requireCap('content.publish');
        }

        return $values;
    }

    /**
     * Cierra un guardado: deja la versión, vacía la caché y avisa a los demás módulos.
     *
     * @param string[] $extraTags etiquetas de caché que hay que vaciar además de las del propio contenido
     */
    public static function finish(string $type, int $id, array $extraTags, App $app): void
    {
        (new Revisions($app))->record($type, $id);
        $app->invalidate(array_values(array_unique(array_merge(['cms', $type . ':' . $id], $extraTags))));
        $app->hooks()->fire('content.saved', $type, $id);
    }

    /**
     * La columna derecha de la ficha: vista previa por idioma y las 20 últimas versiones.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(string $type, ?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }
        $id = (int) $row['id'];
        $previews = [];
        foreach ($app->languages() as $lang) {
            $previews[$lang] = $app->url()->plain('/admin/cms/preview/' . $type . '/' . $id, ['lang' => $lang]);
        }
        $revisions = [];
        foreach ((new Revisions($app))->recent($type, $id) as $revision) {
            $revisions[] = [
                'id' => (int) $revision['id'],
                'when' => $app->i18n()->date(Clock::parse((string) $revision['created_at']), 'medium', $app->site()->timezone()),
                'who' => isset($revision['user_name']) && $revision['user_name'] !== null ? (string) $revision['user_name'] : '',
                'url' => $app->url()->plain('/admin/cms/revisions/' . (int) $revision['id'] . '/restore'),
            ];
        }

        return $app->view()->render('cms::admin/side', [
            'previews' => $previews,
            'revisions' => $revisions,
            'canEdit' => $app->auth()->can('content.edit'),
            'multi' => count($previews) > 1,
        ]);
    }

    /**
     * Acción en bloque «publicar» o «pasar a borrador» de un listado de páginas o de entradas.
     *
     * @param int[] $ids
     */
    public static function bulkStatus(string $type, string $status, array $ids, App $app): string
    {
        if ($status === 'published') {
            $app->auth()->requireCap('content.publish');
        }
        $db = $app->db();
        $table = $type === 'page' ? 'pages' : 'posts';
        $done = 0;
        foreach ($ids as $id) {
            $row = $db->row('SELECT * FROM {' . $table . '} WHERE id = ?', [(int) $id]);
            if ($row === null || (string) $row['status'] === $status) {
                continue;
            }
            $set = ['status' => $status, 'updated_at' => $app->clock()->sql()];
            if ($status === 'published' && (!isset($row['published_at']) || $row['published_at'] === null)) {
                $set['published_at'] = $app->clock()->sql();
            }
            $db->update($table, $set, ['id' => (int) $id]);
            self::finish($type, (int) $id, [], $app);
            ++$done;
        }

        return tn('{n} updated', '{n} updated', $done);
    }
}
