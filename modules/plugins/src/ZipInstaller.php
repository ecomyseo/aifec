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

/**
 * Instala un plugin desde un ZIP que ha subido el administrador.
 *
 * Nada se escribe en plugins/ hasta que el ZIP está entero comprobado: se abre en una carpeta temporal, se exige un único
 * directorio raíz con un plugin.json válido, se rechazan rutas con «..» o absolutas, enlaces simbólicos y ficheros fuera de
 * la raíz, y se ponen topes al tamaño descomprimido (20 MB, contado al escribir y no al fiarse de lo que dice el ZIP) y al
 * número de ficheros (2.000). Después pasa el validador estático y solo entonces se mueve a plugins/<id>/. No lo activa.
 */
final class ZipInstaller
{
    public const MAX_BYTES = 20971520;
    public const MAX_FILES = 2000;

    /** @var Plugins */
    private $plugins;

    public function __construct(Plugins $plugins)
    {
        $this->plugins = $plugins;
    }

    /**
     * @return array<string,mixed> ok, message y, si se instaló, id y report
     */
    public function install(string $zipPath): array
    {
        $i18n = $this->plugins->app()->i18n();
        if (!class_exists('ZipArchive')) {
            return $this->fail($i18n->t('This server cannot open ZIP files (the zip extension is missing)'));
        }
        if (!is_file($zipPath)) {
            return $this->fail($i18n->t('The file was not received'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->fail($i18n->t('The file is not a valid ZIP'));
        }
        $stage = '';
        try {
            $plan = $this->plan($zip);
            if (isset($plan['error'])) {
                return $this->fail((string) $plan['error']);
            }
            $id = (string) $plan['id'];
            $app = $this->plugins->app();
            if ($this->plugins->manager()->manifest($id) !== null || is_dir($app->root() . '/plugins/' . $id)) {
                return $this->fail($i18n->t('«{id}» is already installed; uninstall it first', ['id' => $id]));
            }
            if ($this->plugins->isReservedId($id)) {
                return $this->fail($i18n->t('The identifier «{id}» is reserved by the system', ['id' => $id]));
            }
            // Se extrae a un sitio que imita la instalación: plugins/<id> y el catálogo de textos del núcleo.
            $stage = Fs::makeTemp($this->plugins->tempBase(), 'plugin-zip');
            $target = $stage . '/plugins/' . $id;
            mkdir($target, 0777, true);
            $written = $this->extract($zip, $plan, $target);
            if ($written !== null) {
                return $this->fail($written);
            }
            if (is_dir($app->root() . '/core/lang')) {
                Fs::copyTree($app->root() . '/core/lang', $stage . '/core/lang');
            }
            $raw = json_decode((string) file_get_contents($target . '/plugin.json'), true);
            if (!is_array($raw) || !isset($raw['name']) || !is_string($raw['name']) || trim($raw['name']) === '') {
                return $this->fail($i18n->t('The plugin.json inside the ZIP is not valid'));
            }
            $report = Validator::run($this->plugins, $stage, $id, $raw, false);
            if (!$report['ok']) {
                $errors = [];
                foreach ($report['checks'] as $check) {
                    if (!$check['ok'] && $check['level'] === 'error') {
                        $errors[] = $check['rule'] . ': ' . $check['message'] . ($check['file'] !== '' ? ' (' . $check['file'] . ($check['line'] > 0 ? ':' . $check['line'] : '') . ')' : '');
                    }
                }

                return $this->fail($i18n->t('The plugin does not pass the contract checks and was not installed'), ['errors' => array_slice($errors, 0, 8), 'report' => $report]);
            }
            if (!is_dir($app->root() . '/plugins')) {
                mkdir($app->root() . '/plugins', 0777, true);
            }
            Fs::copyTree($target, $app->root() . '/plugins/' . $id);

            return ['ok' => true, 'message' => $i18n->t('«{id}» has been installed. It is not active yet', ['id' => $id]), 'id' => $id, 'report' => $report];
        } finally {
            $zip->close();
            if ($stage !== '') {
                Fs::rmTree($stage);
            }
        }
    }

    /**
     * Mira todas las entradas del ZIP sin extraer nada y decide qué se extrae.
     *
     * @return array<string,mixed> error, o id y entries (índice => ruta relativa dentro de la raíz; las carpetas, con barra final)
     */
    private function plan(\ZipArchive $zip): array
    {
        $i18n = $this->plugins->app()->i18n();
        $count = (int) $zip->numFiles;
        if ($count < 1) {
            return ['error' => $i18n->t('The ZIP is empty')];
        }
        if ($count > self::MAX_FILES) {
            return ['error' => $i18n->t('The ZIP has more than {max} files', ['max' => self::MAX_FILES])];
        }
        $roots = [];
        $entries = [];
        $declared = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return ['error' => $i18n->t('The ZIP is damaged')];
            }
            $name = (string) $stat['name'];
            if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:/', $name) === 1) {
                return ['error' => $i18n->t('The ZIP has a path that is not allowed: «{path}»', ['path' => $this->printable($name)])];
            }
            $segments = preg_split('~[\\\\/]+~', $name);
            foreach (is_array($segments) ? $segments : [] as $segment) {
                if ($segment === '..') {
                    return ['error' => $i18n->t('The ZIP has a path that is not allowed: «{path}»', ['path' => $this->printable($name)])];
                }
            }
            $first = is_array($segments) ? (string) $segments[0] : '';
            if ($first === '__MACOSX' || basename($name) === '.DS_Store' || basename($name) === 'Thumbs.db') {
                continue;
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
                return ['error' => $i18n->t('The ZIP contains a symbolic link: «{path}»', ['path' => $this->printable($name)])];
            }
            $isDir = substr($name, -1) === '/';
            $roots[$first] = true;
            $declared += (int) $stat['size'];
            $entries[$i] = $name;
            if (!$isDir && count($segments) < 2) {
                return ['error' => $i18n->t('The ZIP has files outside the plugin folder: «{path}»', ['path' => $this->printable($name)])];
            }
        }
        if (count($roots) !== 1) {
            return ['error' => $i18n->t('The ZIP must contain a single folder with the plugin inside')];
        }
        $id = (string) array_keys($roots)[0];
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1) {
            return ['error' => $i18n->t('The plugin folder «{id}» is not a valid identifier (lowercase letters, numbers and hyphens)', ['id' => $this->printable($id)])];
        }
        if (!in_array($id . '/plugin.json', $entries, true)) {
            return ['error' => $i18n->t('The ZIP has no plugin.json in the plugin folder')];
        }
        if ($declared > self::MAX_BYTES) {
            return ['error' => $i18n->t('The plugin is larger than 20 MB once unzipped')];
        }

        return ['id' => $id, 'entries' => $entries];
    }

    /**
     * Escribe las entradas en la carpeta temporal contando los bytes que salen de verdad.
     *
     * @param array<string,mixed> $plan
     *
     * @return string|null el motivo si algo falla
     */
    private function extract(\ZipArchive $zip, array $plan, string $target): ?string
    {
        $i18n = $this->plugins->app()->i18n();
        $id = (string) $plan['id'];
        $total = 0;
        $root = rtrim($target, '/');
        foreach ($plan['entries'] as $index => $name) {
            $relative = (string) substr(str_replace('\\', '/', (string) $name), strlen($id) + 1);
            if ($relative === '') {
                continue;
            }
            $path = $root . '/' . $relative;
            if (substr($name, -1) === '/') {
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
                continue;
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            $stream = $zip->getStream((string) $name);
            if (!is_resource($stream)) {
                return $i18n->t('The ZIP is damaged');
            }
            $out = fopen($path, 'wb');
            if ($out === false) {
                fclose($stream);

                return $i18n->t('The plugin could not be unzipped');
            }
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $total += strlen($chunk);
                if ($total > self::MAX_BYTES) {
                    fclose($out);
                    fclose($stream);

                    return $i18n->t('The plugin is larger than 20 MB once unzipped');
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            fclose($stream);
        }

        return null;
    }

    private function printable(string $text): string
    {
        $text = (string) preg_replace('/[^\x20-\x7e]/', '?', $text);

        return strlen($text) > 80 ? substr($text, 0, 80) . '…' : $text;
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function fail(string $message, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message] + $extra;
    }
}
