<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * Las propuestas de la IA que guarda este módulo (tabla translation_proposals): los textos de la interfaz y las etiquetas de los
 * menús. Una propuesta NO se ve en la tienda: espera aquí hasta que una persona la aprueba (o hasta que se borra). Una sola por
 * idioma + fila + elemento: la nueva sustituye a la anterior.
 */
final class Proposals
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Guarda (o sustituye) la propuesta de un elemento. */
    public function put(string $lang, string $scope, string $item, string $source, string $text, ?int $jobId = null): void
    {
        $hash = sha1($item);
        $now = $this->app->clock()->sql();
        $this->app->db()->transaction(static function ($db) use ($lang, $scope, $item, $hash, $source, $text, $jobId, $now): void {
            $db->delete('translation_proposals', ['lang' => $lang, 'scope' => $scope, 'item_hash' => $hash]);
            $db->insert('translation_proposals', [
                'lang' => $lang,
                'scope' => $scope,
                'item_hash' => $hash,
                'item' => $item,
                'source' => $source,
                'text' => $text,
                'job_id' => $jobId !== null && $jobId > 0 ? $jobId : null,
                'created_at' => $now,
            ]);
        });
    }

    /**
     * Las propuestas pendientes de una fila en un idioma: elemento => [id, text, source].
     *
     * @return array<string,array{id:int,text:string,source:string}>
     */
    public function pending(string $lang, string $scope): array
    {
        $out = [];
        foreach ($this->app->db()->select('SELECT id, item, text, source FROM {translation_proposals} WHERE lang = ? AND scope = ? ORDER BY id', [$lang, $scope]) as $row) {
            $out[(string) $row['item']] = ['id' => (int) $row['id'], 'text' => (string) $row['text'], 'source' => (string) $row['source']];
        }

        return $out;
    }

    public function count(string $lang, string $scope): int
    {
        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {translation_proposals} WHERE lang = ? AND scope = ?', [$lang, $scope]);
    }

    /**
     * Quita las propuestas de unos elementos (aprobadas, rechazadas o ya traducidas a mano).
     *
     * @param string[] $items
     */
    public function remove(string $lang, string $scope, array $items): void
    {
        foreach ($items as $item) {
            $this->app->db()->delete('translation_proposals', ['lang' => $lang, 'scope' => $scope, 'item_hash' => sha1((string) $item)]);
        }
    }
}
