<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;

/**
 * A dónde va el fichero de una exportación y de dónde viene el de una importación.
 *
 * Exportación: download (el fichero de la pasada, descargable en el panel), folder (carpeta privada del sitio), ftp, ftps, post (a una
 * dirección https) y email (adjunto). FTP y FTPS solo se ofrecen si la extensión «ftp» de PHP existe. Los que sacan el fichero de la
 * tienda (ftp, ftps, post, email) respetan el ajuste erp.outbound.
 *
 * Importación: folder, url, ftp y ftps.
 */
final class Destinations
{
    /** Destinos que sacan datos de la tienda. */
    public const LEAVING = ['ftp', 'ftps', 'post', 'email'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Los destinos que se pueden elegir aquí: código => etiqueta.
     *
     * @return array<string,string>
     */
    public static function available(App $app, bool $import = false): array
    {
        if ($import) {
            $out = ['folder' => 'A private folder of the site', 'url' => 'An https address'];
        } else {
            $out = ['download' => 'Download from the panel', 'folder' => 'A private folder of the site'];
        }
        $ftp = $app->service('erp.ftp');
        if ($ftp instanceof FtpTransport && $ftp->available(false)) {
            $out['ftp'] = 'FTP server (not encrypted)';
        }
        if ($ftp instanceof FtpTransport && $ftp->available(true)) {
            $out['ftps'] = 'FTPS server (FTP over TLS)';
        }
        if (!$import) {
            $out['post'] = 'POST to an https address';
            if ($app->has('mailer')) {
                $out['email'] = 'Email with the file attached';
            }
        }

        return $out;
    }

    /**
     * Entrega el fichero. Devuelve una nota corta («uploaded to /in/»). Lanza \RuntimeException con el motivo en inglés si falla.
     *
     * @param array<string,mixed> $profile la fila de erp_exports (dest ya descodificado en 'dest_data')
     */
    public function deliver(array $profile, string $fileName, string $contents, string $mime): string
    {
        $dest = isset($profile['dest_data']) && is_array($profile['dest_data']) ? $profile['dest_data'] : [];
        $id = (int) $profile['id'];
        switch ((string) $profile['destination']) {
            case 'download':
                return 'kept for download';
            case 'folder':
                $dir = $this->folder('out', isset($dest['folder']) ? (string) $dest['folder'] : 'profile-' . $id);
                $target = $dir . '/' . $fileName;
                if (file_put_contents($target . '.part', $contents) === false || !rename($target . '.part', $target)) {
                    throw new \RuntimeException('The file could not be written to the folder');
                }

                return 'saved in the folder';
            case 'ftp':
            case 'ftps':
                $connection = $this->connection($profile, $dest);
                $path = rtrim((string) ($dest['path'] ?? ''), '/') . '/' . $fileName;
                $ftp = $this->ftp();
                $ftp->put($connection, $path . '.part', $contents);
                $ftp->rename($connection, $path . '.part', $path);

                return 'uploaded to ' . ((string) ($dest['path'] ?? '') !== '' ? (string) $dest['path'] : '/');
            case 'post':
                $url = (string) ($dest['url'] ?? '');
                $problem = $this->app->service('erp')->webhooks()->urlProblem($url);
                if ($problem !== null) {
                    throw new \RuntimeException($problem);
                }
                $headers = ['Content-Type' => $mime, 'X-Filename' => $fileName, 'X-Aifec-Profile' => (string) $id];
                $token = $this->app->secret('erp.post.' . $id);
                if ($token !== '') {
                    $headers['Authorization'] = 'Bearer ' . $token;
                }
                $response = $this->app->http()->send(new HttpRequest('POST', $url, $headers, $contents, 30.0));
                if (!$response->ok()) {
                    throw new \RuntimeException('The server answered ' . $response->status);
                }

                return 'sent, the server answered ' . $response->status;
            case 'email':
                $to = (string) ($dest['email'] ?? '');
                if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || !$this->app->has('mailer')) {
                    throw new \RuntimeException('There is no valid email address to send the file to');
                }
                $this->app->service('mailer')->queue('erp_export_file', $to, ['profile' => (string) $profile['name'], 'file' => $fileName], [
                    'locale' => $this->app->defaultLang(), 'related' => 'erp-export:' . $id,
                    'attachments' => [['name' => $fileName, 'content' => $contents, 'mime' => $mime]],
                ]);

                return 'emailed to ' . $to;
        }
        throw new \RuntimeException('Unknown destination');
    }

    /**
     * Los ficheros pendientes de una importación, el más antiguo primero: [nombre, contenido, quitar] con «quitar» = qué hacer
     * cuando se ha procesado (moverlo a «processed»).
     *
     * @param array<string,mixed> $profile
     *
     * @return array<int,array{name:string,contents:string,done:callable}>
     */
    public function sources(array $profile, int $max): array
    {
        $dest = isset($profile['dest_data']) && is_array($profile['dest_data']) ? $profile['dest_data'] : [];
        $pattern = isset($dest['pattern']) && (string) $dest['pattern'] !== '' ? (string) $dest['pattern'] : '*';
        $out = [];
        switch ((string) $profile['destination']) {
            case 'folder':
                $dir = $this->folder('in', isset($dest['folder']) ? (string) $dest['folder'] : 'profile-' . (int) $profile['id']);
                $files = [];
                foreach (is_array(scandir($dir)) ? scandir($dir) : [] as $name) {
                    if ($name !== '.' && $name !== '..' && is_file($dir . '/' . $name) && fnmatch($pattern, $name)) {
                        $files[$name] = (int) filemtime($dir . '/' . $name);
                    }
                }
                asort($files);
                foreach (array_slice(array_keys($files), 0, $max) as $name) {
                    $name = (string) $name;
                    $path = $dir . '/' . $name;
                    $contents = file_get_contents($path);
                    if ($contents === false) {
                        throw new \RuntimeException('The file could not be read: ' . $name);
                    }
                    $out[] = ['name' => $name, 'contents' => $contents, 'done' => static function () use ($dir, $name, $path): void {
                        if (!is_dir($dir . '/processed')) {
                            mkdir($dir . '/processed', 0775, true);
                        }
                        rename($path, $dir . '/processed/' . $name);
                    }];
                }

                return $out;
            case 'url':
                $url = (string) ($dest['url'] ?? '');
                $problem = $this->app->service('erp')->webhooks()->urlProblem($url);
                if ($problem !== null) {
                    throw new \RuntimeException($problem);
                }
                $response = $this->app->http()->send(HttpRequest::get($url, [], 30.0));
                if (!$response->ok()) {
                    throw new \RuntimeException('The server answered ' . $response->status);
                }
                $name = basename((string) parse_url($url, PHP_URL_PATH));

                return [['name' => $name !== '' ? $name : 'download', 'contents' => $response->body, 'done' => static function (): void {
                }]];
            case 'ftp':
            case 'ftps':
                $connection = $this->connection($profile, $dest);
                $base = rtrim((string) ($dest['path'] ?? ''), '/');
                $ftp = $this->ftp();
                $names = array_values(array_filter($ftp->names($connection, $base === '' ? '/' : $base), static function (string $n) use ($pattern): bool {
                    return $n !== '' && $n !== '.' && $n !== '..' && fnmatch($pattern, $n);
                }));
                sort($names);
                foreach (array_slice($names, 0, $max) as $name) {
                    $from = $base . '/' . $name;
                    $out[] = ['name' => $name, 'contents' => $ftp->get($connection, $from), 'done' => function () use ($ftp, $connection, $base, $from, $name): void {
                        $ftp->rename($connection, $from, $base . '/processed/' . $name);
                    }];
                }

                return $out;
        }
        throw new \RuntimeException('Unknown source');
    }

    /** La carpeta privada de un perfil («out» o «in»), creada si no existe y con el acceso web denegado. */
    public function folder(string $kind, string $name): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '.-');
        $name = $name === '' ? 'profile' : $name;
        $root = $this->app->site()->path('private/erp', true);
        foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $file => $content) {
            if (is_dir($root) && !is_file($root . '/' . $file)) {
                file_put_contents($root . '/' . $file, $content);
            }
        }
        $dir = $this->app->site()->path('private/erp/' . $kind . '/' . $name, true);
        if (!is_dir($dir)) {
            throw new \RuntimeException('The folder could not be created');
        }

        return $dir;
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $dest
     *
     * @return array<string,mixed>
     */
    private function connection(array $profile, array $dest): array
    {
        $host = trim((string) ($dest['host'] ?? ''));
        if ($host === '') {
            throw new \RuntimeException('The FTP server is not set');
        }

        return [
            'host' => $host, 'port' => (int) ($dest['port'] ?? 21) > 0 ? (int) ($dest['port'] ?? 21) : 21, 'user' => (string) ($dest['user'] ?? ''),
            'password' => $this->app->secret('erp.ftp.' . (int) $profile['id']), 'secure' => (string) $profile['destination'] === 'ftps',
            'passive' => !isset($dest['passive']) || !empty($dest['passive']), 'fingerprint' => (string) ($dest['fingerprint'] ?? ''),
        ];
    }

    private function ftp(): FtpTransport
    {
        $ftp = $this->app->service('erp.ftp');
        if (!$ftp instanceof FtpTransport) {
            throw new \RuntimeException('The FTP service is not available');
        }

        return $ftp;
    }
}
