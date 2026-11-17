<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;
use Aifec\Security\Random;

/**
 * Las conversaciones del asistente y sus mensajes (tablas «conversations» y «conversation_messages»).
 *
 * Una conversación pertenece a un navegador: lleva una ficha aleatoria en una cookie propia del sitio y en la tabla solo
 * se guarda su resumen SHA-256, así que ni una copia de la base de datos sirve para entrar en la conversación de nadie.
 * Lo que escribe el cliente se guarda tal cual (se escapa al pintarlo) y puede llevar datos personales: por eso se
 * borra todo pasados los días que diga el ajuste «assistant.retention_days».
 */
final class Conversations
{
    /** Lo más que se guarda de un mensaje. */
    public const MAX_MESSAGE = 1000;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Nombre de la cookie de la conversación. */
    public function cookieName(): string
    {
        return $this->app->cookieName('as');
    }

    /**
     * Abre una conversación nueva.
     *
     * @return array{id:int,token:string} token = la ficha en claro, para la cookie del navegador
     */
    public function create(string $lang): array
    {
        $token = Random::token(16);
        $now = $this->app->clock()->sql();
        $id = $this->app->db()->insert('conversations', [
            'token_hash' => hash('sha256', $token),
            'lang' => substr($lang, 0, 8),
            'mode' => 'rules',
            'started_at' => $now,
            'last_at' => $now,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return null;
        }

        return $this->app->db()->row('SELECT * FROM {conversations} WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {conversations} WHERE id = ?', [$id]);
    }

    /**
     * Lo que el asistente recuerda de la conversación: qué espera del cliente («expect») y las últimas fichas que enseñó.
     *
     * @param array<string,mixed> $conversation
     *
     * @return array<string,mixed>
     */
    public function state(array $conversation): array
    {
        $raw = isset($conversation['state']) ? (string) $conversation['state'] : '';
        $state = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($state) ? $state : [];
    }

    /**
     * Apunta un mensaje y devuelve su identificador. Un mensaje del cliente suma uno al contador de la conversación.
     *
     * @param array<int,array<string,mixed>> $tools  las herramientas que se usaron para contestar
     * @param array<string,int|null>         $timing lo que tardó una respuesta del asistente: ai_ms (null = no se usó la IA), tools_ms y rounds
     */
    public function addMessage(int $conversationId, string $role, string $content, array $tools = [], array $timing = []): int
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $row = [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => mb_substr($content, 0, 4000, 'UTF-8'),
            'tools' => $tools === [] ? null : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $now,
        ];
        if ($timing !== []) {
            $row['ai_ms'] = isset($timing['ai_ms']) ? max(0, (int) $timing['ai_ms']) : null;
            $row['tools_ms'] = isset($timing['tools_ms']) ? max(0, (int) $timing['tools_ms']) : 0;
            $row['rounds'] = isset($timing['rounds']) ? max(0, (int) $timing['rounds']) : 0;
        }
        $id = $db->insert('conversation_messages', $row);
        $db->run(
            'UPDATE {conversations} SET last_at = ?, messages = messages + ? WHERE id = ?',
            [$now, $role === 'user' ? 1 : 0, $conversationId]
        );

        return $id;
    }

    /** Marca un mensaje del cliente como «no supe contestarlo», con su texto normalizado para agrupar. */
    public function markUnanswered(int $messageId, int $conversationId, string $text): void
    {
        $db = $this->app->db();
        $db->update('conversation_messages', ['unanswered' => 1, 'phrase' => self::phrase($text)], ['id' => $messageId]);
        $db->run('UPDATE {conversations} SET unanswered = unanswered + 1 WHERE id = ?', [$conversationId]);
    }

    /**
     * Cambios en la conversación: mode, state (array), cart_added, checkout y order_id (el pedido en que acabó).
     *
     * @param array<string,mixed> $changes
     */
    public function update(int $conversationId, array $changes): void
    {
        $set = [];
        foreach (['mode', 'cart_added', 'checkout'] as $column) {
            if (array_key_exists($column, $changes)) {
                $set[$column] = $column === 'mode' ? (string) $changes[$column] : (int) (bool) $changes[$column];
            }
        }
        if (isset($changes['order_id']) && (int) $changes['order_id'] > 0) {
            $set['order_id'] = (int) $changes['order_id'];
        }
        if (array_key_exists('state', $changes)) {
            $set['state'] = is_array($changes['state']) && $changes['state'] !== []
                ? (string) json_encode($changes['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;
        }
        if ($set !== []) {
            $this->app->db()->update('conversations', $set, ['id' => $conversationId]);
        }
    }

    /**
     * Los últimos mensajes (del cliente y del asistente) en orden de antigüedad.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public function history(int $conversationId, int $limit = 12): array
    {
        $rows = $this->app->db()->select(
            "SELECT role, content FROM {conversation_messages} WHERE conversation_id = ? AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT ?",
            [$conversationId, max(1, $limit)]
        );
        $out = [];
        foreach (array_reverse($rows) as $row) {
            $out[] = ['role' => (string) $row['role'], 'content' => (string) $row['content']];
        }

        return $out;
    }

    /** La conversación acabó en un pedido: lo apunta quien tenga esta cookie (si ya había una conversación). */
    public function markOrder(string $token, int $orderId): bool
    {
        $conversation = $this->findByToken($token);
        if ($conversation === null || $orderId <= 0) {
            return false;
        }

        return $this->app->db()->update('conversations', ['order_id' => $orderId], ['id' => (int) $conversation['id']]) > 0;
    }

    /** Un pedido que acabó fallido (el cobro no arrancó) deja de contar como el desenlace de la conversación. */
    public function unmarkOrder(int $conversationId, int $orderId): void
    {
        $this->app->db()->run('UPDATE {conversations} SET order_id = NULL WHERE id = ? AND order_id = ?', [$conversationId, $orderId]);
    }

    /**
     * Borra las conversaciones que llevan más de $days días sin actividad, con sus mensajes. Con 0 o menos no borra nada.
     *
     * @return array{conversations:int,messages:int}
     */
    public function purge(int $days): array
    {
        if ($days < 1) {
            return ['conversations' => 0, 'messages' => 0];
        }
        $cutoff = $this->app->clock()->sql($this->app->clock()->now() - $days * 86400);
        $db = $this->app->db();
        $messages = $db->run('DELETE FROM {conversation_messages} WHERE conversation_id IN (SELECT id FROM {conversations} WHERE last_at < ?)', [$cutoff]);
        $conversations = $db->run('DELETE FROM {conversations} WHERE last_at < ?', [$cutoff]);

        return ['conversations' => $conversations, 'messages' => $messages];
    }

    /**
     * El texto de una pregunta normalizado para agrupar las que son iguales: en minúsculas, sin correos ni números largos
     * (teléfonos, pedidos), con los espacios sueltos recogidos y a 160 caracteres como mucho.
     */
    public static function phrase(string $text): string
    {
        $t = mb_strtolower(trim(mb_scrub($text, 'UTF-8')), 'UTF-8');
        $t = preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', '…', $t);
        $t = preg_replace('/\d(?:[\s.-]?\d){5,}/', '…', $t === null ? '' : $t);
        $t = preg_replace('/\s+/u', ' ', $t === null ? '' : $t);

        return mb_substr(trim($t === null ? '' : $t), 0, 160, 'UTF-8');
    }
}
