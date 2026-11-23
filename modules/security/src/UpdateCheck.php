<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;

/**
 * ¿Hay una versión de AIFEC más nueva que la instalada? Lee la última release de GitHub una vez al día y lo apunta en un ajuste.
 * Solo avisa: no descarga ni instala nada.
 */
final class UpdateCheck
{
    public const ENDPOINT = 'https://api.github.com/repos/ecomyseo/aifec/releases/latest';
    private const KEY = 'security.update_latest';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Pregunta a GitHub y guarda el resultado. Un fallo no borra lo que ya se sabía.
     *
     * @return array<string,mixed>|null lo guardado, o null si no hubo respuesta útil
     */
    public function refresh(): ?array
    {
        if (!$this->app->settings()->bool('security.update_check', true)) {
            return null;
        }
        try {
            $response = $this->app->http()->send(HttpRequest::get(self::ENDPOINT, ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'AIFEC/' . App::VERSION], 6.0));
        } catch (\Throwable $e) {
            $this->app->log()->warning('update check: ' . $e->getMessage());

            return null;
        }
        $data = $response->status === 200 ? json_decode($response->body, true) : null;
        $tag = is_array($data) && isset($data['tag_name']) && is_string($data['tag_name']) ? ltrim($data['tag_name'], 'vV') : '';
        if ($tag === '' || preg_match('/^\d+\.\d+\.\d+$/', $tag) !== 1) {
            $this->app->log()->warning('update check: answer ' . $response->status . ' without a valid version');

            return null;
        }
        $zip = false;
        foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && isset($asset['name']) && is_string($asset['name']) && substr($asset['name'], -4) === '.zip') {
                $zip = true;
            }
        }
        $url = isset($data['html_url']) && is_string($data['html_url']) && strpos($data['html_url'], 'https://github.com/') === 0 ? $data['html_url'] : 'https://github.com/ecomyseo/aifec/releases';
        $saved = ['version' => $tag, 'url' => $url, 'zip' => $zip, 'checked_at' => $this->app->clock()->now()];
        $this->app->settings()->set(self::KEY, json_encode($saved));

        return $saved;
    }

    /**
     * Lo último que se supo de GitHub.
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $raw = $this->app->settings()->str(self::KEY, '');
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) && isset($data['version']) ? $data : null;
    }

    /** La versión nueva si la hay (mayor que la instalada), o null. */
    public function newer(): ?array
    {
        $latest = $this->latest();

        return $latest !== null && version_compare((string) $latest['version'], App::VERSION, '>') ? $latest : null;
    }
}
