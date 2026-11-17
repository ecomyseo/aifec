<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Install;

use Aifec\Kernel\Clock;
use Aifec\Kernel\I18n;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Crypto;
use Aifec\Security\Random;
use Aifec\Security\Signer;
use Aifec\View\View;

/**
 * Instalador web: tres pantallas (idioma y comprobaciones, base de datos, tu tienda) y una final.
 * Solo lo llama Front cuando no hay ningún sitio. No hay aplicación todavía, así que lleva su propia traducción.
 *
 * Lo que viaja entre pantallas va en un campo oculto FIRMADO (HMAC con la clave temporal de <datos>/install.key,
 * que se borra al terminar). La contraseña de MySQL no vuelve nunca al navegador: se guarda cifrada en ese
 * mismo fichero. Sin JavaScript.
 */
final class Installer
{
    private const LANGS = ['es' => 'Español', 'en' => 'English'];

    private const STATE_TTL = 7200;

    /** @var string */
    private $root;

    /** @var string */
    private $dataRoot;

    /** @var string */
    private $publicDir;

    /** @var string */
    private $publicBase;

    /** @var Clock */
    private $clock;

    /** @var I18n */
    private $i18n;

    /** @var View */
    private $view;

    /** @var string */
    private $lang = 'en';

    private function __construct(string $root, string $dataRoot, string $publicDir, string $publicBase)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        $this->publicDir = rtrim(str_replace('\\', '/', $publicDir), '/');
        $this->publicBase = rtrim($publicBase, '/');
        $this->clock = new Clock();
        $this->i18n = new I18n('en');
        $this->i18n->addDir($this->root . '/core/lang');
        $this->i18n->addDir($this->root . '/core/views/install/lang');
        $this->view = new View();
        $this->view->addNamespace('install', $this->root . '/core/views/install');
    }

    public static function handle(Request $request, string $root, string $dataRoot, string $publicDir, string $publicBase): Response
    {
        $installer = new self($root, $dataRoot, $publicDir, $publicBase);

        try {
            return $installer->run($request);
        } catch (\Throwable $e) {
            // Sin aplicación no hay registro donde apuntar: se enseña el motivo, que en un instalador es lo que hace falta.
            return Response::text('The installer failed: ' . $e->getMessage(), 500);
        }
    }

    private function run(Request $request): Response
    {
        if ($request->isPost()) {
            if (!$request->isSameOrigin()) {
                return Response::text('The form must be sent from this same site.', 403);
            }

            return $this->post($request);
        }
        $this->setLang($this->chooseLang($request->str('lang'), $request));

        return $this->screenOne([], 200);
    }

    // ------------------------------------------------------------------ envíos

    private function post(Request $request): Response
    {
        $step = $request->int('step', 0);

        if ($step === 1) {
            $this->setLang($this->chooseLang($request->str('lang'), $request));
            if ($this->blocked()) {
                return $this->screenOne(['error' => $this->tr('Fix the items marked ✗ and reload this page.')], 200);
            }

            return $this->screenTwo($this->newState(), [], [], 200);
        }

        $state = $this->readState($request->str('state'));
        if ($state === null) {
            $this->setLang($this->chooseLang($request->str('lang'), $request));

            return $this->screenOne(['notice' => $this->tr('The form data expired or was changed. Start again.')], 200);
        }
        $this->setLang($state['lang']);
        $back = $request->str('go') === 'back';

        if ($step === 2) {
            return $back ? $this->screenOne([], 200) : $this->submitDatabase($request, $state);
        }
        if ($step === 3) {
            return $back ? $this->screenTwo($state, $state['db'], [], 200) : $this->submitShop($request, $state);
        }

        return $this->screenOne([], 200);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function submitDatabase(Request $request, array $state): Response
    {
        $input = [
            'driver' => $request->str('db_driver'), 'host' => $request->str('db_host'), 'port' => $request->str('db_port'),
            'name' => $request->str('db_name'), 'user' => $request->str('db_user'), 'prefix' => $request->str('db_prefix'),
        ];
        $errors = [];
        if (!in_array($input['driver'], ['sqlite', 'mysql'], true)) {
            $errors['db_driver'] = $this->tr('Choose a database type');
        }
        if ($input['driver'] === 'mysql') {
            if (preg_match(SiteFactory::HOST_PATTERN, $input['host']) !== 1) {
                $errors['db_host'] = $this->tr('Type the server name, for example localhost');
            }
            if (preg_match('/^\d{1,5}$/', $input['port']) !== 1 || (int) $input['port'] < 1 || (int) $input['port'] > 65535) {
                $errors['db_port'] = $this->tr('Type a port between 1 and 65535');
            }
            if (preg_match(SiteFactory::DB_NAME_PATTERN, $input['name']) !== 1) {
                $errors['db_name'] = $this->tr('Type the name of an existing database');
            }
            if ($input['user'] === '' || strlen($input['user']) > 80) {
                $errors['db_user'] = $this->tr('Required field');
            }
            if (preg_match(SiteFactory::PREFIX_PATTERN, $input['prefix']) !== 1) {
                $errors['db_prefix'] = $this->tr('Use lowercase letters, digits and underscores, starting with a letter');
            }
        }
        if ($errors !== []) {
            return $this->screenTwo($state, $input, $errors, 422);
        }

        $file = $this->keyFile();
        $password = (string) $request->input('db_password', '');
        $identity = hash('sha256', implode('|', [$input['host'], $input['port'], $input['name'], $input['user']]));
        $stored = $this->storedPassword($file, $identity);
        if ($input['driver'] === 'mysql' && $password === '' && $stored !== null) {
            $password = $stored;
        }

        $config = ['driver' => $input['driver']];
        if ($input['driver'] === 'mysql') {
            $config += ['host' => $input['host'], 'port' => (int) $input['port'], 'name' => $input['name'], 'user' => $input['user'], 'password' => $password, 'prefix' => $input['prefix']];
        }
        try {
            SiteFactory::checkDatabase($config);
        } catch (\InvalidArgumentException $e) {
            return $this->screenTwo($state, $input, [], 422, $this->message($e));
        }

        // La contraseña buena se guarda cifrada en el servidor; al navegador no vuelve.
        $file['db'] = $input['driver'] === 'mysql' && $password !== ''
            ? ['id' => $identity, 'pw' => (new Crypto($file['key']))->encrypt($password)]
            : null;
        $this->saveKeyFile($file);
        foreach ($input as $key => $value) {
            if ($value !== '') {
                $state['db'][$key] = $value;
            }
        }

        return $this->screenThree($state, [], [], 200);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function submitShop(Request $request, array $state): Response
    {
        $input = [
            'shop_name' => $request->str('shop_name'), 'shop_email' => strtolower($request->str('shop_email')),
            'admin_name' => $request->str('admin_name'), 'admin_email' => strtolower($request->str('admin_email')),
        ];
        $password = (string) $request->input('admin_password', '');
        $again = (string) $request->input('admin_password2', '');
        $errors = [];
        if ($input['shop_name'] === '' || mb_strlen($input['shop_name']) > 120) {
            $errors['shop_name'] = $this->tr('Required field');
        }
        if (filter_var($input['shop_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['shop_email'] = $this->tr('Type a valid email address');
        }
        if ($input['admin_name'] === '' || mb_strlen($input['admin_name']) > 120) {
            $errors['admin_name'] = $this->tr('Required field');
        }
        if (filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = $this->tr('Type a valid email address');
        }
        if (mb_strlen($password) < 10) {
            $errors['admin_password'] = $this->tr('Use at least {n} characters', ['n' => 10]);
        } elseif ($password !== $again) {
            $errors['admin_password2'] = $this->tr('The two values do not match');
        }
        if ($errors !== []) {
            return $this->screenThree($state, $input, $errors, 422);
        }

        $db = ['driver' => 'sqlite'];
        $file = $this->keyFile();
        if ($state['db']['driver'] === 'mysql') {
            $d = $state['db'];
            $identity = hash('sha256', implode('|', [$d['host'], $d['port'], $d['name'], $d['user']]));
            $stored = $this->storedPassword($file, $identity);
            if ($stored === null && is_array($file['db'])) {
                return $this->screenThree($state, $input, [], 422, $this->tr('The database password is not saved any more. Go back and type it again.'));
            }
            $db = ['driver' => 'mysql', 'host' => $d['host'], 'port' => (int) $d['port'], 'name' => $d['name'], 'user' => $d['user'],
                'password' => $stored !== null ? $stored : '', 'prefix' => $d['prefix']];
        }

        try {
            SiteFactory::create($this->root, $this->dataRoot, [
                'id' => 'main', 'host' => '*', 'path' => '',
                'url' => $request->scheme() . '://' . $request->host . $this->publicBase,
                'locale' => $this->lang, 'timezone' => $this->lang === 'es' ? 'Europe/Madrid' : 'UTC',
                'db' => $db, 'shop_name' => $input['shop_name'], 'shop_email' => $input['shop_email'],
                'admin' => ['name' => $input['admin_name'], 'email' => $input['admin_email'], 'password' => $password],
                'public_dir' => $this->publicDir,
            ]);
        } catch (\Throwable $e) {
            return $this->screenThree($state, $input, [], 422, $this->message($e));
        }

        // Terminado: la clave temporal ya no hace falta (y con ella, nada de lo firmado vale).
        if (is_file($this->dataRoot . '/install.key')) {
            @unlink($this->dataRoot . '/install.key');
        }
        $url = $request->scheme() . '://' . $request->host . $this->publicBase;

        return $this->page('done', ['title' => 'AIFEC is installed', 'step' => 4, 'shopUrl' => $url . '/', 'adminUrl' => $url . '/admin', 'adminEmail' => $input['admin_email']]);
    }

    // ------------------------------------------------------------------ pantallas

    /**
     * @param array<string,string> $extra error o notice
     */
    private function screenOne(array $extra, int $status): Response
    {
        $checks = Requirements::check($this->dataRoot, $this->publicDir);

        return $this->page('step1', $extra + [
            'title' => 'Welcome to AIFEC', 'step' => 1, 'checks' => $checks, 'blocked' => $this->blockedBy($checks),
        ], $status);
    }

    /**
     * @param array<string,mixed>  $state
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function screenTwo(array $state, array $input, array $errors, int $status, string $error = ''): Response
    {
        $input = $input + $state['db'];

        return $this->page('step2', [
            'title' => 'Database', 'step' => 2, 'state' => $this->sign($state), 'input' => $input, 'errors' => $errors, 'error' => $error,
            'hasSqlite' => extension_loaded('pdo_sqlite'), 'hasMysql' => extension_loaded('pdo_mysql'),
        ], $status);
    }

    /**
     * @param array<string,mixed>  $state
     * @param array<string,string> $input
     * @param array<string,string> $errors
     */
    private function screenThree(array $state, array $input, array $errors, int $status, string $error = ''): Response
    {
        $input += ['shop_name' => '', 'shop_email' => '', 'admin_name' => '', 'admin_email' => ''];

        return $this->page('step3', [
            'title' => 'Your shop', 'step' => 3, 'state' => $this->sign($state), 'input' => $input, 'errors' => $errors, 'error' => $error,
        ], $status);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function page(string $name, array $data, int $status = 200): Response
    {
        $data += [
            't' => function (string $text, array $vars = []): string {
                return $this->i18n->t($text, $vars);
            },
            'lang' => $this->lang, 'langs' => self::LANGS, 'error' => '', 'notice' => '',
            'cssUrl' => $this->publicBase . '/assets/c/core/dev/admin/admin.css',
            'action' => $this->publicBase . '/',
        ];

        return Response::html($this->view->render('install::' . $name, $data), $status)->header('Cache-Control', 'no-store');
    }

    // ------------------------------------------------------------------ idioma y mensajes

    /**
     * @param array<string,string|int|float> $vars
     */
    private function tr(string $text, array $vars = []): string
    {
        return $this->i18n->t($text, $vars);
    }

    private function setLang(string $lang): void
    {
        $this->lang = isset(self::LANGS[$lang]) ? $lang : 'en';
        $this->i18n->setLocale($this->lang);
    }

    private function chooseLang(string $requested, Request $request): string
    {
        if (isset(self::LANGS[$requested])) {
            return $requested;
        }

        return strtolower(substr((string) $request->header('accept-language', ''), 0, 2)) === 'es' ? 'es' : 'en';
    }

    /** Mensaje de una excepción de SiteFactory, traducido (el detalle de una conexión fallida va dentro de otro texto). */
    private function message(\Throwable $e): string
    {
        $text = $e->getMessage();
        if (strpos($text, SiteFactory::CONNECT_ERROR) === 0) {
            return $this->tr('Could not connect to the database: {detail}', ['detail' => substr($text, strlen(SiteFactory::CONNECT_ERROR))]);
        }

        return $this->tr($text);
    }

    // ------------------------------------------------------------------ comprobaciones

    private function blocked(): bool
    {
        return $this->blockedBy(Requirements::check($this->dataRoot, $this->publicDir));
    }

    /**
     * @param array<int,array{id:string,label:string,ok:bool,required:bool,detail:string,impact:string}> $checks
     */
    private function blockedBy(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ estado firmado y clave temporal

    /**
     * @return array{lang:string,db:array<string,string>}
     */
    private function newState(): array
    {
        return ['lang' => $this->lang, 'db' => ['driver' => 'sqlite', 'host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'prefix' => 'af_']];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function sign(array $state): string
    {
        $file = $this->keyFile();

        return (new Signer($file['key']))->sign($state, $this->clock->now() + self::STATE_TTL);
    }

    /**
     * @return array{lang:string,db:array<string,string>}|null
     */
    private function readState(string $token): ?array
    {
        $path = $this->dataRoot . '/install.key';
        if ($token === '' || !is_file($path)) {
            return null;
        }
        $file = $this->keyFile();
        $data = (new Signer($file['key']))->verify($token, $this->clock->now());
        if ($data === null || !isset($data['lang'], $data['db']) || !is_string($data['lang']) || !isset(self::LANGS[$data['lang']]) || !is_array($data['db'])) {
            return null;
        }
        $state = $this->newState();
        $state['lang'] = $data['lang'];
        foreach (array_keys($state['db']) as $key) {
            if (isset($data['db'][$key]) && is_scalar($data['db'][$key])) {
                $state['db'][$key] = (string) $data['db'][$key];
            }
        }

        return $state;
    }

    /**
     * El fichero temporal: la clave de firma y, si hay MySQL, su contraseña cifrada. Se crea la primera vez que hace falta.
     *
     * @return array{key:string,db:array<string,string>|null}
     */
    private function keyFile(): array
    {
        $path = $this->dataRoot . '/install.key';
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['key']) && is_string($data['key']) && strlen($data['key']) >= 32) {
                return ['key' => $data['key'], 'db' => isset($data['db']) && is_array($data['db']) ? $data['db'] : null];
            }
        }
        $file = ['key' => Random::token(32), 'db' => null];
        $this->saveKeyFile($file);

        return $file;
    }

    /**
     * @param array{key:string,db:array<string,string>|null} $file
     */
    private function saveKeyFile(array $file): void
    {
        if (!is_dir($this->dataRoot) && !mkdir($this->dataRoot, 0775, true) && !is_dir($this->dataRoot)) {
            throw new \RuntimeException('Could not create the data folder');
        }
        SiteFactory::protectDataRoot($this->dataRoot, $this->publicDir);
        $tmp = $this->dataRoot . '/install.key.' . bin2hex(random_bytes(3)) . '.tmp';
        if (file_put_contents($tmp, (string) json_encode($file), LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the installer key');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->dataRoot . '/install.key')) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the installer key');
        }
    }

    /**
     * @param array{key:string,db:array<string,string>|null} $file
     */
    private function storedPassword(array $file, string $identity): ?string
    {
        if (!is_array($file['db']) || !isset($file['db']['id'], $file['db']['pw']) || $file['db']['id'] !== $identity) {
            return null;
        }

        return (new Crypto($file['key']))->decrypt((string) $file['db']['pw']);
    }
}
