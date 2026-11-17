<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Generators;

use Aifec\Ai\AiMessage;
use Aifec\Ai\Prompt;

/**
 * El título o la descripción para buscadores de una página de contenido (aviso legal, quiénes somos, envíos…), escritos con el
 * texto de la página. Como todo lo de la IA, queda como propuesta: nada se publica hasta que una persona lo aprueba
 * (14/11/2026: «no encontré ninguna forma de pedir a la IA una propuesta de texto para una página»).
 */
final class PageMetaGenerator extends Generator
{
    /** @var string meta_title o meta_description */
    private $kind;

    public function __construct(string $kind)
    {
        $this->kind = $kind === 'meta_title' ? 'meta_title' : 'meta_description';
    }

    public function task(): string
    {
        return 'page.' . $this->kind;
    }

    public function field(): string
    {
        return $this->kind;
    }

    public function entity(): string
    {
        return 'page';
    }

    protected function instruction(string $language, array $options): string
    {
        if ($this->kind === 'meta_title') {
            return 'You write the search engine title of a content page of an online shop. Write in ' . $language . '. '
                . 'Plain text, at most 60 characters, that says what the page is about. Use only what the page says. No quotes, no emojis. '
                . 'Answer with a JSON object that has a "title" string.';
        }

        return 'You write the search engine description of a content page of an online shop. Write in ' . $language . '. '
            . 'One or two sentences of plain text, about 150 characters, that say what the reader will find on the page. '
            . 'Use only what the page says and never invent anything. No quotes, no emojis. '
            . 'Answer with a JSON object that has a "description" string.';
    }

    protected function schema(array $options): array
    {
        $key = $this->kind === 'meta_title' ? 'title' : 'description';

        return ['type' => 'object', 'properties' => [$key => ['type' => 'string']], 'required' => [$key]];
    }

    public function request(array $context, string $lang, array $options = []): array
    {
        $page = isset($context['page']) && is_array($context['page']) ? $context['page'] : [];
        $text = 'Title: ' . (isset($page['title']) ? (string) $page['title'] : '') . "\n"
            . 'Text: ' . mb_substr(isset($page['text']) ? (string) $page['text'] : '', 0, 4000, 'UTF-8');

        return [
            'system' => $this->instruction(self::languageName($lang), $options),
            'messages' => [AiMessage::user('Page data follows.' . "\n" . Prompt::fence('PAGE', $text))],
            'schema' => $this->schema($options),
            'max_tokens' => $this->maxTokens(),
        ];
    }

    public function proposed(array $json, array $context): ?string
    {
        return $this->kind === 'meta_title' ? self::line($json, 'title', 70) : self::line($json, 'description', 170);
    }
}
