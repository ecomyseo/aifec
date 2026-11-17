<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Plugins;

use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Site;

/**
 * Un sitio de pruebas desechable para ejecutar un plugin sin tocar el sitio de verdad: una copia de la instalación
 * (núcleo sin código, módulos, temas y el propio plugin) y una base de datos SQLite nueva. Se borra al terminar.
 *
 * La copia del código se hace una vez por proceso y por instalación; cada sitio de pruebas parte de ella.
 */
final class Sandbox
{
    /** @var array<string,string> raíz real => carpeta con la copia del código */
    private static $templates = [];

    /** @var string */
    private $dir;

    /** @var string */
    private $root;

    /** @var string */
    private $siteDir;

    /** @var string */
    private $publicDir;

    /** @var array<string,mixed> */
    private $config;

    /** @var string[] */
    private $ids;

    /** @var int */
    private $now;

    private function __construct()
    {
    }

    public static function create(Plugins $plugins, string $realRoot, string $pluginId, int $now): self
    {
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $base = $plugins->tempBase();
        if (!isset(self::$templates[$realRoot]) || !is_dir(self::$templates[$realRoot]) || strpos(self::$templates[$realRoot], $base) !== 0) {
            $tpl = Fs::makeTemp($base, 'sandbox-code');
            Fs::copyTree($realRoot . '/core', $tpl . '/core', static function (string $rel): bool {
                return $rel === 'src' || $rel === 'assets';
            });
            Fs::copyTree($realRoot . '/modules', $tpl . '/modules', static function (string $rel): bool {
                return preg_match('~^[^/]+/tests(/|$)~', $rel) === 1;
            });
            if (is_dir($realRoot . '/themes')) {
                Fs::copyTree($realRoot . '/themes', $tpl . '/themes');
            }
            self::$templates[$realRoot] = $tpl;
            // La copia del código solo sirve mientras vive este proceso: al salir se borra.
            register_shutdown_function(static function () use ($tpl): void {
                Fs::rmTree($tpl);
            });
        }
        $self = new self();
        $self->dir = Fs::makeTemp($base, 'sandbox');
        $self->root = $self->dir . '/root';
        Fs::copyTree(self::$templates[$realRoot], $self->root);
        mkdir($self->root . '/plugins', 0777, true);
        Fs::copyTree($realRoot . '/plugins/' . $pluginId, $self->root . '/plugins/' . $pluginId);
        $self->siteDir = $self->dir . '/site';
        $self->publicDir = $self->dir . '/public';
        mkdir($self->siteDir, 0777, true);
        mkdir($self->publicDir, 0777, true);
        $self->now = $now;
        $self->config = [
            'url' => 'https://tienda.test',
            'secret' => str_repeat('k', 48),
            'locale' => 'es',
            'timezone' => 'Europe/Madrid',
            'debug' => true,
            'db' => ['driver' => 'sqlite', 'path' => $self->siteDir . '/db.sqlite', 'prefix' => 'af_'],
        ];
        $ids = [];
        foreach (['modules', 'plugins'] as $folder) {
            $dirs = glob($self->root . '/' . $folder . '/*', GLOB_ONLYDIR);
            foreach (is_array($dirs) ? $dirs : [] as $d) {
                $ids[] = basename($d);
            }
        }
        $self->ids = $ids;

        return $self;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Una aplicación nueva sobre el sitio de pruebas (la base de datos y los ajustes son los mismos de las anteriores). */
    public function app(): App
    {
        $site = new Site('contract-check', $this->siteDir, $this->config, '', 'tienda.test');

        return new App($site, $this->root, [
            'clock' => new Clock($this->now),
            'http' => new FakeHttpClient(),
            'log_files' => false,
            'public_dir' => $this->publicDir,
            'modules' => $this->ids,
        ]);
    }

    public function dispose(): void
    {
        gc_collect_cycles();
        Fs::rmTree($this->dir);
    }
}
