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
use Aifec\Modules\Translations\Scope;

/**
 * Los textos editables de los correos (asunto, saludo y despedida de cada plantilla). Solo se cuentan aquí, por el servicio público
 * del módulo de correo («mailer»: templates(), texts() y defaults()): se ven, se editan y se traducen con IA en su propia pantalla
 * («Plantillas de correo»), que ya trae la ayuda de la IA. Un texto cuenta como traducido si el comercio lo escribió en ese idioma
 * o si el de serie tiene traducción en el catálogo de la interfaz.
 */
final class MailScope implements Scope
{
    private const FIELDS = ['subject', 'greeting', 'farewell'];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return t('Emails');
    }

    public function describe(): string
    {
        return t('Subject, greeting and sign-off of each email; they are translated in the email templates');
    }

    public function entities(): array
    {
        return ['mail_template'];
    }

    public function sourceLang(): string
    {
        return $this->app->defaultLang();
    }

    public function brief(): string
    {
        return 'Texts of the emails of an online shop.';
    }

    public function blocked(string $lang): string
    {
        return t('Emails are translated in the email templates, also with AI');
    }

    public function coverage(string $lang): array
    {
        $out = ['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0];
        $default = $this->app->defaultLang();
        if ($lang === $default || !$this->app->has('mailer')) {
            return $out;
        }
        $mailer = $this->app->service('mailer');
        foreach (array_keys($mailer->templates()) as $key) {
            $own = $mailer->texts((string) $key, $lang);
            $serie = $mailer->defaults((string) $key, $lang);
            $english = $mailer->defaults((string) $key, 'en');
            $source = $mailer->texts((string) $key, $default);
            $base = $mailer->defaults((string) $key, $default);
            foreach (self::FIELDS as $field) {
                $from = (string) $source[$field] !== '' ? (string) $source[$field] : (string) $base[$field];
                if ($from === '') {
                    continue;
                }
                ++$out['total'];
                if ((string) $own[$field] !== '' || $lang === 'en' || ((string) $serie[$field] !== '' && (string) $serie[$field] !== (string) $english[$field])) {
                    ++$out['translated'];
                } else {
                    ++$out['missing'];
                }
            }
        }

        return $out;
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        return ['units' => [], 'cursor' => $after, 'done' => true];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        return array_keys($texts);
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        return 0;
    }

    public function approvalNeedsAi(): bool
    {
        return false;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/mail/templates');
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        return null;
    }

    public function tokensPerUnit(): array
    {
        return [60, 70];
    }
}
