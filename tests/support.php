<?php
/**
 * AIFEC — apoyo para las pruebas que necesitan el sistema montado: un sitio temporal y un «navegador».
 *
 *   $app = testApp();                         sitio nuevo y vacío sobre el código real, con reloj fijo y sin red
 *   $web = new TestClient($app);
 *   $r = $web->get('/carrito');               $r es Aifec\Kernel\Response
 *   $r = $web->post('/carrito/add', ['id' => 5]);
 *   $web->cookies                             las cookies se conservan entre peticiones, como en un navegador
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Kernel\Site;

final class AifecTestEnv
{
    /** @var array<string,string> bases de datos ya migradas (una por conjunto de módulos) que se copian para cada sitio de prueba */
    public static $templates = [];

    /** @var string[] lo que han lanzado los oyentes de ganchos durante la prueba en curso (en producción solo se apunta en el registro) */
    public static $hookErrors = [];

    /** @var bool la prueba en curso espera que algún oyente lance (expectHookErrors()) */
    public static $hookErrorsExpected = false;
}

/**
 * La prueba espera que algún oyente de un gancho lance una excepción (por ejemplo, para comprobar que la tienda sigue en pie).
 * Sin esta llamada, un oyente que lanza hace fallar la prueba: en producción el error solo se apunta y se sigue, y una
 * prueba que no lo viera daría por bueno un gancho que no hace nada.
 */
function expectHookErrors(): void
{
    AifecTestEnv::$hookErrorsExpected = true;
}

/** Instante fijo de las pruebas: 12/11/2026 12:00:00 UTC. */
function testNow(): int
{
    return (int) strtotime('2026-11-12 12:00:00 UTC');
}

/**
 * Sitio de prueba recién creado sobre el código real del proyecto.
 *
 * @param array<string,mixed> $options modules (lista de módulos que se cargan; por defecto ninguno; '*' = todos),
 *                                     config (array que se funde con la configuración del sitio), root (otra raíz
 *                                     de instalación), base_path («/demos/x»), settings (ajustes iniciales),
 *                                     clock, http
 */
function testApp(array $options = []): App
{
    $root = isset($options['root']) ? (string) $options['root'] : Autoload::root();
    $dir = tmpDir('aifec-site');
    $config = [
        'url' => 'https://tienda.test' . (isset($options['base_path']) ? (string) $options['base_path'] : ''),
        'secret' => str_repeat('k', 48),
        'locale' => 'es',
        'timezone' => 'Europe/Madrid',
        'debug' => true,
        // En las pruebas las hojas van ENLAZADAS (en producción van escritas dentro de la página): así un «la página no
        // contiene la clase X» mira el HTML y no la hoja. Las pruebas de las hojas dentro de la página lo encienden con
        // 'config' => ['inline_css' => true] (tests/Themes/InlineCssTest.php).
        'inline_css' => false,
        'db' => ['driver' => 'sqlite', 'path' => $dir . '/db.sqlite', 'prefix' => 'af_'],
    ];
    if (isset($options['config']) && is_array($options['config'])) {
        $config = array_replace_recursive($config, $options['config']);
    }

    // Módulos que existen para esta prueba. Por defecto ninguno (solo el núcleo): así las pruebas de un módulo no
    // dependen de los demás. 'modules' => ['mail'] carga esos y los que necesiten; 'modules' => '*' los carga todos.
    $only = isset($options['root']) ? null : [];
    if (array_key_exists('modules', $options)) {
        $only = is_array($options['modules']) ? array_values($options['modules']) : null;
    }
    $extra = $only !== null ? ['modules' => $only] : [];

    // La base migrada se construye una vez por pasada y por conjunto de módulos, y se copia: cada prueba empieza limpia y rápido.
    $key = md5($root . '|' . ($only === null ? '*' : implode(',', $only)));
    if (!isset($options['root']) && !isset($options['fresh'])) {
        if (!isset(AifecTestEnv::$templates[$key])) {
            $tplDir = tmpDir('aifec-tpl');
            $tplConfig = $config;
            $tplConfig['db']['path'] = $tplDir . '/db.sqlite';
            $tpl = new App(new Site('tpl', $tplDir, $tplConfig), $root, ['clock' => new Clock(testNow()), 'log_files' => false] + $extra);
            $tpl->boot();
            $tpl->db()->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            AifecTestEnv::$templates[$key] = $tplDir . '/db.sqlite';
        }
        copy(AifecTestEnv::$templates[$key], $dir . '/db.sqlite');
    }

    $site = new Site('test', $dir, $config, isset($options['base_path']) ? (string) $options['base_path'] : '', 'tienda.test');
    $app = new App($site, $root, [
        'clock' => isset($options['clock']) ? $options['clock'] : new Clock(testNow()),
        'http' => isset($options['http']) ? $options['http'] : new FakeHttpClient(),
        'log_files' => false,
        'public_dir' => $dir . '/public',
    ] + $extra);
    // Lo que lance un oyente se apunta como en producción y, además, se guarda para que el corredor haga fallar la prueba.
    $app->hooks()->onError(static function (string $module, string $hook, \Throwable $e) use ($app): void {
        AifecTestEnv::$hookErrors[] = 'gancho «' . $hook . '» (' . $module . '): ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
        $app->log()->exception($e, 'hook ' . $hook . ' (' . $module . ')');
    });
    // El correo de las pruebas no sale por la red ni por mail(): va a un transporte de mentira que lo guarda. Sin él, el
    // envío inmediato fallaba siempre y el mensaje se quedaba «pending», que durante un tiempo se dio por «enviado»: así
    // pasó inadvertido que en producción un fallo pasajero dejaba el código del comprador sin salir (16/11/2026). Una prueba
    // que quiera otro (uno que falle, o mirar lo enviado) lo sustituye con $app->bind('mail.transport', …); y con
    // 'mail_transport' => false se queda sin ninguno, para probar el que eligen los ajustes.
    if (!array_key_exists('mail_transport', $options) || $options['mail_transport'] !== false) {
        $outbox = new \Aifec\Mail\ArrayTransport();
        $app->bind('mail.transport', static function () use ($outbox): \Aifec\Mail\Transport {
            return $outbox;
        });
    }
    if (isset($options['settings']) && is_array($options['settings'])) {
        $app->migrate(false);
        $app->settings()->setMany($options['settings']);
    }
    $app->makeCurrent();

    return $app;
}

/**
 * Navegador de mentira: manda peticiones a la aplicación sin servidor web y conserva las cookies.
 */
final class TestClient
{
    /** @var App */
    public $app;

    /** @var array<string,string> */
    public $cookies = [];

    /** @var Response|null */
    public $last;

    /** @var array<string,string> cabeceras que van en todas las peticiones */
    public $headers = ['sec-fetch-site' => 'same-origin', 'user-agent' => 'AifecTest/1.0'];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $headers
     */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->send('GET', $uri, [], $headers);
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->send('POST', $uri, $data, $headers);
    }

    /**
     * Manda JSON y pide JSON, como el JavaScript de la tienda.
     *
     * @param array<string,mixed> $data
     */
    public function postJson(string $uri, array $data = []): Response
    {
        $request = Request::create('POST', $uri, [], $this->cookies, $this->headers + [
            'content-type' => 'application/json', 'accept' => 'application/json', 'x-requested-with' => 'fetch',
        ], (string) json_encode($data));

        return $this->handle($request);
    }

    /**
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     */
    public function send(string $method, string $uri, array $post = [], array $headers = []): Response
    {
        return $this->handle(Request::create($method, $uri, $post, $this->cookies, $headers + $this->headers));
    }

    /** Sigue la redirección de la última respuesta, si la hay. */
    public function follow(): Response
    {
        if ($this->last === null || !$this->last->isRedirect()) {
            throw new \LogicException('The last response is not a redirect');
        }
        $location = $this->last->headers['Location'];
        $location = (string) preg_replace('#^https?://[^/]+#', '', $location);
        // Un navegador no manda al servidor lo que va detrás de la almohadilla.
        $location = (string) preg_replace('/#.*$/', '', $location);
        $base = $this->app->site()->basePath;
        if ($base !== '' && strpos($location, $base) === 0) {
            $location = (string) substr($location, strlen($base));
        }

        return $this->get($location === '' ? '/' : $location);
    }

    /**
     * @return array<string,mixed> cuerpo de la última respuesta como JSON
     */
    public function json(): array
    {
        $data = $this->last !== null ? json_decode($this->last->body, true) : null;

        return is_array($data) ? $data : [];
    }

    private function handle(Request $request): Response
    {
        $request->host = 'tienda.test';
        $request->secure = true;
        $response = $this->app->handle($request);
        foreach ($response->cookies as $name => $cookie) {
            if ($cookie['value'] === '' || ((int) $cookie['options']['expires'] !== 0 && (int) $cookie['options']['expires'] < $this->app->clock()->now())) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $cookie['value'];
            }
        }
        // Una respuesta por partes se escribe DESPUÉS de las cabeceras: se ejecuta ahora, con las cookies ya recogidas. Lo que
        // se encole mientras se escribe no llega al navegador, igual que en una petición de verdad; lo escrito queda en el cuerpo.
        $response->drain();
        $this->last = $response;

        return $response;
    }
}

/**
 * Da de alta una persona del equipo y devuelve su id. La contraseña de todas es «clave-larga-1234».
 */
function aifecAdmin(App $app, string $role = 'admin', string $email = 'ana@tienda.test', string $name = 'Ana Ruiz'): int
{
    static $hash = null;
    if ($hash === null) {
        $hash = \Aifec\Security\Passwords::hash('clave-larga-1234');
    }
    $app->boot();
    $now = $app->clock()->sql();

    return $app->db()->insert('users', ['email' => $email, 'name' => $name, 'password' => $hash, 'role' => $role, 'locale' => 'es', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
}

/** Entra al panel con el navegador de mentira. */
function aifecLogin(TestClient $web, string $email = 'ana@tienda.test', string $password = 'clave-larga-1234'): Response
{
    return $web->post('/admin/login', ['email' => $email, 'password' => $password]);
}

/** Ficha de formulario de la última página del panel abierta: va en «_token» en cada envío. */
function aifecToken(TestClient $web): string
{
    $body = $web->last !== null ? $web->last->body : '';
    if (preg_match('/name="af-token" content="([^"]+)"/', $body, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    throw new \LogicException('No form token in the last page: open a panel page first');
}

/**
 * Lo más corto para probar una pantalla del panel: persona dada de alta, sesión iniciada y una página abierta
 * (así aifecToken($web) ya funciona).
 */
function adminClient(App $app, string $role = 'admin'): TestClient
{
    aifecAdmin($app, $role, $role . '@tienda.test', ucfirst($role));
    $web = new TestClient($app);
    aifecLogin($web, $role . '@tienda.test');
    $web->get('/admin');

    return $web;
}

/** Valor (ya descodificado) del <input> con ese nombre en un HTML, o null si no está. */
function inputValue(string $html, string $name): ?string
{
    if (preg_match('/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*\bvalue="([^"]*)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return null;
}

/*
 * Documentos de identidad DE MENTIRA para las pruebas, con su letra o su dígito de control bien calculados (las
 * validaciones de la tienda los comprueban). En el proyecto no se escribe ningún DNI que pueda ser de alguien: los escritos
 * son de ejemplo (12345678Z, X1234567L, B12345674…) y lo vigila tests/Privacy/NoRealIdsTest.php; cuando hace falta variedad,
 * se generan aquí al azar, al ejecutar la prueba, y no quedan escritos en ningún sitio. El cálculo está hecho aparte del
 * de Aifec\Geo\Spain a propósito: así la prueba compara dos cuentas distintas.
 */

/** Un DNI con su letra: el del número dado o uno al azar. `fakeDni(12345678)` da «12345678Z». */
function fakeDni(?int $number = null): string
{
    $number = $number ?? random_int(0, 99999999);

    return sprintf('%08d', $number) . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
}

/** Un NIE (X, Y o Z, siete cifras y letra): el dado o uno al azar. `fakeNie('X', 1234567)` da «X1234567L». */
function fakeNie(?string $prefix = null, ?int $number = null): string
{
    $prefix = $prefix ?? 'XYZ'[random_int(0, 2)];
    $number = $number ?? random_int(0, 9999999);
    $full = (int) strpos('XYZ', $prefix) * 10000000 + $number;

    return $prefix . sprintf('%07d', $number) . 'TRWAGMYFPDXBNJZSQVHLCKE'[$full % 23];
}

/**
 * Un NIF de entidad (letra, siete cifras y control): el dado o uno al azar. Las entidades N, P, Q, R, S y W llevan el
 * control en letra y las demás aquí lo llevan en cifra. `fakeCompanyTaxId('B', 1234567)` da «B12345674».
 */
function fakeCompanyTaxId(?string $type = null, ?int $number = null): string
{
    $types = 'ABCDEFGHJNPQRSUVW';
    $type = $type ?? $types[random_int(0, strlen($types) - 1)];
    $digits = sprintf('%07d', $number ?? random_int(0, 9999999));
    $sum = 0;
    foreach (str_split($digits) as $position => $digit) {
        $value = (int) $digit;
        if ($position % 2 === 0) {
            // Las posiciones impares (primera, tercera…) valen el doble, sumando sus dos cifras.
            $value *= 2;
            $value = $value > 9 ? $value - 9 : $value;
        }
        $sum += $value;
    }
    $control = (10 - $sum % 10) % 10;

    return $type . $digits . (strpos('NPQRSW', $type) !== false ? 'JABCDEFGHI'[$control] : (string) $control);
}
