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

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Las redirecciones que tenía el origen (tabla «redirects»).
 *
 * No pisa una dirección que ya responde con contenido (una página, un producto o una categoría de aquí), ni una redirección que alguien puso aquí
 * a otro destino. Una redirección cuya cadena de destinos acabaría dando vueltas se descarta con su motivo.
 */
final class RedirectsWriter extends WriterBase
{
    /** Saltos que se siguen como mucho al buscar un bucle. */
    private const MAX_HOPS = 10;

    public function type(): string
    {
        return 'redirects';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $source = $this->path((string) $record['source']);
        $target = $this->target((string) $record['target']);
        if ($source === '') {
            return $this->result(self::SKIPPED, null, 'The address of the redirect is empty');
        }
        $code = isset($record['code']) && is_int($record['code']) ? $record['code'] : 301;
        $active = isset($record['active']) && $record['active'] === false ? 0 : 1;
        $found = $this->resolve('redirects', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('redirects', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        $reason = $this->rejection($source, $target, $code, $row);
        if ($reason !== '') {
            return $this->result(self::SKIPPED, null, $reason);
        }
        $same = $row === null ? $db->row('SELECT * FROM {redirects} WHERE source = ?', [$source]) : null;
        if ($same !== null && !(bool) $ctx->option('overwrite_local', false)) {
            if ((string) $same['target'] === $target && (int) $same['code'] === $code) {
                if (!$ctx->dryRun()) {
                    $ctx->remember('redirects', $id, (int) $same['id'], Neutral::hash($record));
                }

                return $this->result(self::UNCHANGED, (int) $same['id']);
            }

            return $this->result(self::CONFLICT, (int) $same['id'], 'There is already a redirect from this address to somewhere else');
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $data = ['source' => $source, 'target' => $target, 'code' => $code, 'active' => $active];
        if ($row === null && $same !== null) {
            $row = $same;
            $action = self::UPDATED;
        }
        if ($row === null) {
            $localId = $db->insert('redirects', $data + ['hits' => 0, 'note' => 'Import', 'created_at' => $this->now()]);
        } else {
            $localId = (int) $row['id'];
            $db->update('redirects', $data, ['id' => $localId]);
        }
        $ctx->remember('redirects', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * Por qué NO se puede escribir esta redirección ('' si se puede).
     *
     * @param array<string,mixed>|null $row la redirección de aquí que ya es esta (su pareja), si la hay
     */
    private function rejection(string $source, string $target, int $code, ?array $row): string
    {
        $db = $this->db();
        $own = $row !== null ? (int) $row['id'] : 0;
        if ($db->tableExists('slugs') && $db->row('SELECT id FROM {slugs} WHERE path = ?', [$source]) !== null) {
            return 'The address already shows content here, so it was not redirected';
        }
        if ($code === 410) {
            return '';
        }
        if ($this->absolute($target)) {
            return '';
        }
        $next = $this->path($target);
        if ($next === $source) {
            return 'The redirect points to the same address';
        }
        // Se sigue la cadena de destinos que ya hay: si vuelve al origen de esta redirección, daría vueltas.
        $seen = [$source => true, $next => true];
        for ($hop = 0; $hop < self::MAX_HOPS; ++$hop) {
            $other = $db->row('SELECT id, target FROM {redirects} WHERE source = ? AND active = 1', [$next]);
            if ($other === null || (int) $other['id'] === $own || $this->absolute((string) $other['target'])) {
                return '';
            }
            $next = $this->path((string) $other['target']);
            if ($next === $source) {
                return 'The redirect would go round in circles';
            }
            if (isset($seen[$next])) {
                return '';
            }
            $seen[$next] = true;
        }

        return 'The chain of redirects is too long';
    }

    /** La ruta sin barras en los extremos, sin la cadena de consulta y sin barras repetidas. */
    private function path(string $path): string
    {
        $path = trim($path);
        if ($this->absolute($path)) {
            $parsed = parse_url($path);

            return is_array($parsed) && isset($parsed['path']) ? trim((string) preg_replace('#/+#', '/', (string) $parsed['path']), '/') : '';
        }
        $cut = strcspn($path, '?#');

        return trim((string) preg_replace('#/+#', '/', substr($path, 0, $cut)), '/');
    }

    /** El destino tal como se guarda: una dirección completa, o una ruta con barra delante. */
    private function target(string $target): string
    {
        $target = trim($target);

        return $this->absolute($target) || $target === '' || $target[0] === '/' ? $target : '/' . $target;
    }

    private function absolute(string $url): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($url)) === 1;
    }
}
