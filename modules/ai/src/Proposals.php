<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Kernel\App;

/**
 * Las propuestas de la IA: la IA propone y una persona aprueba. Nada se escribe en la tienda hasta que alguien aprueba, y
 * aprobar es llamar al filtro «ai.apply» `(bool $hecho, array $propuesta)`, que implementa el módulo dueño del campo. Si nadie
 * lo aplica, la propuesta se queda pendiente (no se pierde ni se da por buena).
 *
 * Una sola pendiente por entidad + campo + idioma: la nueva sustituye a la anterior.
 */
final class Proposals
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Resultados de approve(). */
    public const OK = 'approved';
    public const NOT_PENDING = 'not_pending';
    public const NOBODY_APPLIES = 'nobody_applies';
    public const MISSING = 'missing';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Crea una propuesta pendiente y devuelve su id.
     *
     * @param array<string,mixed> $meta current (texto actual; si falta se lee), model, task, user_id
     *
     * @throws \InvalidArgumentException si el texto está vacío o no tiene la forma del campo
     */
    public function propose(string $entity, int $entityId, string $field, ?string $lang, string $proposed, array $meta = []): int
    {
        $entity = trim($entity);
        $field = trim($field);
        $lang = $lang === null ? '' : strtolower(trim($lang));
        if ($entity === '' || strlen($entity) > 32 || $field === '' || strlen($field) > 40 || strlen($lang) > 8 || $entityId <= 0) {
            throw new \InvalidArgumentException('Invalid proposal target');
        }
        $proposed = $this->normalise($entity, $field, $proposed);
        $current = isset($meta['current']) && is_string($meta['current']) ? $meta['current'] : $this->currentText($entity, $entityId, $field, $lang);
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : $this->userId();

        $db = $this->app->db();

        return (int) $db->transaction(function ($db) use ($entity, $entityId, $field, $lang, $proposed, $current, $meta, $userId): int {
            $db->delete('ai_proposals', ['entity' => $entity, 'entity_id' => $entityId, 'field' => $field, 'lang' => $lang, 'status' => self::PENDING]);

            return $db->insert('ai_proposals', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'field' => $field,
                'lang' => $lang,
                'current_text' => $current,
                'proposed_text' => $proposed,
                'status' => self::PENDING,
                'model' => isset($meta['model']) && is_string($meta['model']) ? substr($meta['model'], 0, 120) : '',
                'task' => isset($meta['task']) && is_string($meta['task']) ? substr($meta['task'], 0, 80) : '',
                'created_by' => $userId > 0 ? $userId : null,
                'created_at' => $this->app->clock()->sql(),
            ]);
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {ai_proposals} WHERE id = ?', [$id]);
    }

    public function pendingCount(): int
    {
        return (int) $this->app->db()->value("SELECT COUNT(*) FROM {ai_proposals} WHERE status = 'pending'");
    }

    /**
     * Cambia el texto propuesto de una pendiente (la persona lo corrige antes de aprobar).
     *
     * @throws \InvalidArgumentException si no existe, ya no está pendiente o el texto no vale
     */
    public function edit(int $id, string $text): void
    {
        $proposal = $this->find($id);
        if ($proposal === null || (string) $proposal['status'] !== self::PENDING) {
            throw new \InvalidArgumentException('The proposal is not pending');
        }
        $text = $this->normalise((string) $proposal['entity'], (string) $proposal['field'], $text);
        $this->app->db()->update('ai_proposals', ['proposed_text' => $text], ['id' => $id, 'status' => self::PENDING]);
    }

    /**
     * Aprueba una propuesta: la aplica (filtro «ai.apply»), deja la marca y avisa con la acción «ai.approved». Con $edited, antes
     * guarda ese texto como el propuesto.
     *
     * Devuelve OK, NOT_PENDING (ya estaba resuelta), NOBODY_APPLIES (ningún módulo sabe aplicarla: queda pendiente) o MISSING.
     *
     * @throws \InvalidArgumentException si el texto corregido no vale
     */
    public function approve(int $id, ?string $edited = null): string
    {
        $proposal = $this->find($id);
        if ($proposal === null) {
            return self::MISSING;
        }
        if ((string) $proposal['status'] !== self::PENDING) {
            return self::NOT_PENDING;
        }
        if ($edited !== null && $edited !== (string) $proposal['proposed_text']) {
            $this->edit($id, $edited);
            $proposal = $this->find($id);
            if ($proposal === null) {
                return self::MISSING;
            }
        }

        $db = $this->app->db();
        $userId = $this->userId();
        $now = $this->app->clock()->sql();
        // «Solo el primero gana»: se reclama antes de aplicar, y se devuelve a pendiente si nadie la aplica.
        $claimed = $db->run(
            "UPDATE {ai_proposals} SET status = 'approved', resolved_by = ?, resolved_at = ? WHERE id = ? AND status = 'pending'",
            [$userId > 0 ? $userId : null, $now, $id]
        ) === 1;
        if (!$claimed) {
            return self::NOT_PENDING;
        }

        $applied = false;
        try {
            $applied = $this->app->hooks()->apply('ai.apply', false, $proposal) === true;
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'ai:apply');
        }
        if (!$applied) {
            $db->run("UPDATE {ai_proposals} SET status = 'pending', resolved_by = NULL, resolved_at = NULL WHERE id = ? AND status = 'approved'", [$id]);

            return self::NOBODY_APPLIES;
        }

        $db->insert('ai_marks', [
            'entity' => (string) $proposal['entity'],
            'entity_id' => (int) $proposal['entity_id'],
            'field' => (string) $proposal['field'],
            'lang' => (string) $proposal['lang'],
            'user_id' => $userId > 0 ? $userId : null,
            'model' => (string) $proposal['model'],
            'created_at' => $now,
        ]);
        $proposal['status'] = self::APPROVED;
        $proposal['resolved_by'] = $userId > 0 ? $userId : null;
        $proposal['resolved_at'] = $now;
        $this->app->hooks()->fire('ai.approved', $proposal);

        return self::OK;
    }

    /** Rechaza una pendiente. No toca nada más. true = se rechazó ahora. */
    public function reject(int $id): bool
    {
        $userId = $this->userId();

        return $this->app->db()->run(
            "UPDATE {ai_proposals} SET status = 'rejected', resolved_by = ?, resolved_at = ? WHERE id = ? AND status = 'pending'",
            [$userId > 0 ? $userId : null, $this->app->clock()->sql(), $id]
        ) === 1;
    }

    /**
     * Un texto propuesto o actual como se lee y se compara en la bandeja: el HTML pasa a párrafos de texto y las listas de datos o
     * de preguntas se dejan como JSON legible.
     */
    public static function display(string $field, string $text): string
    {
        if (!in_array($field, ProductFields::HTML_FIELDS, true)) {
            return $text;
        }
        $text = (string) preg_replace('#</(p|li|h[1-6]|div|tr)>|<br\s*/?>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R/u', $text);
        $out = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------ interior

    /**
     * Deja el texto propuesto como se guarda: recortado y, si es una lista de datos o de preguntas, como JSON legible.
     *
     * @throws \InvalidArgumentException
     */
    private function normalise(string $entity, string $field, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('The proposal is empty');
        }
        if ($entity === 'product' && ($field === 'facts' || $field === 'faqs')) {
            $list = ProductFields::decodeList($field, $text);
            if ($list === null || $list === []) {
                throw new \InvalidArgumentException('The proposal does not have the shape of the field');
            }

            return ProductFields::encode($list);
        }

        return $text;
    }

    private function currentText(string $entity, int $id, string $field, string $lang): string
    {
        $db = $this->app->db();
        if ($entity === 'product' && ProductFields::isField($field) && $db->tableExists('product_i18n')) {
            return ProductFields::read($this->app, $id, $lang !== '' ? $lang : $this->app->defaultLang(), $field);
        }
        if ($entity === 'page' && in_array($field, ['title', 'excerpt', 'meta_title', 'meta_description'], true) && $db->tableExists('page_i18n')) {
            $value = $db->value('SELECT ' . ($field === 'title' ? 'title' : ($field === 'excerpt' ? 'excerpt' : ($field === 'meta_title' ? 'meta_title' : 'meta_description')))
                . ' FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, $lang !== '' ? $lang : $this->app->defaultLang()]);

            return is_string($value) ? $value : '';
        }
        if ($entity === 'media' && $field === 'alt' && $db->tableExists('media')) {
            $alt = $db->value('SELECT alt FROM {media} WHERE id = ?', [$id]);

            return is_string($alt) ? $alt : '';
        }

        return '';
    }

    private function userId(): int
    {
        try {
            $id = $this->app->auth()->adminId();
        } catch (\Throwable $e) {
            return 0;
        }

        return $id !== null ? $id : 0;
    }
}
