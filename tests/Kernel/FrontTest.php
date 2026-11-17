<?php
/**
 * AIFEC — pruebas del punto de entrada: sitios por subcarpeta, caché de página, recursos y registro de visitas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\AssetServer;
use Aifec\Kernel\Front;
use Aifec\Kernel\Request;
use Aifec\Kernel\SiteResolver;
use Aifec\Kernel\VisitLog;

/**
 * Instalación de mentira con dos sitios: el principal y uno en /demos/moda.
 *
 * @return array{0:string,1:string,2:string} raíz, carpeta de datos y carpeta pública
 */
function aifecFrontInstall(): array
{
    $root = aifecFakeRoot();
    aifecPut($root . '/themes/base/assets/css/a.css', 'body{color:red}');
    aifecPut($root . '/themes/base/assets/secreto.php', '<?php echo 1;');
    aifecPut($root . '/modules/demo/assets/m.js', 'var a=1;');
    aifecPut($root . '/modules/demo/cli.php', "<?php\nreturn ['demo:hola' => ['help' => 'Says hello', 'run' => function (\$app, array \$args, \$io): int { \$io->line('hola ' . (isset(\$args[0]) ? \$args[0] : '') . ' en ' . \$app->site()->id . (isset(\$args['alto']) ? '!' : '')); return 0; }]];\n");
    $data = tmpDir('aifec-data');
    $public = tmpDir('aifec-public');
    foreach (['main' => '', 'moda' => '/demos/moda'] as $id => $path) {
        aifecPut($data . '/' . $id . '/config.php', "<?php\nreturn " . var_export([
            'url' => 'http://tienda.test' . $path, 'secret' => str_repeat('s', 40), 'locale' => 'es', 'debug' => true,
            'db' => ['driver' => 'sqlite', 'path' => $data . '/' . $id . '/db.sqlite'],
        ], true) . ";\n");
    }
    SiteResolver::save($data, [['id' => 'main', 'host' => '*', 'path' => ''], ['id' => 'moda', 'host' => '*', 'path' => '/demos/moda']]);

    return [$root, $data, $public];
}

function aifecFrontGet(array $i, string $uri, array $headers = [], array $cookies = []): \Aifec\Kernel\Response
{
    $request = Request::create('GET', $uri, [], $cookies, $headers);
    $request->host = 'tienda.test';

    return Front::run($i[0], $i[1], $i[2], '', $request);
}

test('cada dirección cae en su sitio y las rutas del sitio en subcarpeta llevan su prefijo', function (): void {
    $i = aifecFrontInstall();
    same('HOME lang=es url=/carrito?a=1', aifecFrontGet($i, '/')->body);
    same('HOME lang=es url=/demos/moda/carrito?a=1', aifecFrontGet($i, '/demos/moda/')->body);
    $r = aifecFrontGet($i, '/demos/moda');
    same(301, $r->status);
    same('/demos/moda/', $r->headers['Location']);
    same(404, aifecFrontGet($i, '/demos/otra')->status, '«/demos/otra» es del sitio principal y allí no existe');
    same('main', SiteResolver::resolve($i[1], 'tienda.test:8080', '/demos/modaX')->id, 'un prefijo parecido no es el mismo sitio');
});

test('dos sitios del mismo dominio no comparten sesión', function (): void {
    $i = aifecFrontInstall();
    $request = Request::create('POST', '/demos/moda/guardar', ['nota' => 'de moda']);
    $request->host = 'tienda.test';
    $r = Front::run($i[0], $i[1], $i[2], '', $request);
    $cookies = [];
    foreach ($r->cookies as $name => $cookie) {
        $cookies[$name] = $cookie['value'];
    }
    check($cookies !== [], 'el sitio en subcarpeta pone su cookie');
    $moda = json_decode(aifecFrontGet($i, '/demos/moda/leer', [], $cookies)->body, true);
    same('de moda', $moda['nota']);
    $main = json_decode(aifecFrontGet($i, '/leer', [], $cookies)->body, true);
    same(null, $main['nota'], 'el sitio principal no lee la sesión del otro aunque le llegue su cookie');
});

test('caché de página: se guarda lo marcado, se sirve sin arrancar y se invalida por etiqueta', function (): void {
    $i = aifecFrontInstall();
    $seed = Request::create('GET', '/');
    $seed->host = 'tienda.test';
    $app = new \Aifec\Kernel\App(SiteResolver::load($i[1], 'main'), $i[0], ['log_files' => false]);
    $app->boot();
    $app->slugs()->set('thing', 5, 'es', 'cosa/silla');

    $first = aifecFrontGet($i, '/cosa/silla');
    same('THING 5', $first->body);
    same('MISS', $first->headers['X-Cache']);
    $second = aifecFrontGet($i, '/cosa/silla?utm_source=x&gclid=1');
    same('HIT', $second->headers['X-Cache'], 'los parámetros de seguimiento no crean otra copia');
    same('THING 5', $second->body);
    check(!isset($second->headers['Server-Timing']), 'la copia se sirve sin pasar por la aplicación');

    // La cookie de sesión de cliente sola (un carrito) ya no deja sin copia; la cuenta abierta (marca «u») y el panel, sí.
    $session = aifecFrontGet($i, '/cosa/silla', [], [$app->cookieName('c') => str_repeat('a', 64)]);
    same('HIT', $session->headers['X-Cache'], 'con cookie de sesión de cliente y sin cuenta abierta, sale la copia');
    $logged = aifecFrontGet($i, '/cosa/silla', [], [$app->cookieName('c') => str_repeat('a', 64), $app->cookieName('u') => '1']);
    check(!isset($logged->headers['X-Cache']) || $logged->headers['X-Cache'] !== 'HIT', 'con la cuenta abierta no sale de la caché');
    $admin = aifecFrontGet($i, '/cosa/silla', [], [$app->cookieName('a') => str_repeat('a', 64)]);
    check(!isset($admin->headers['X-Cache']) || $admin->headers['X-Cache'] !== 'HIT', 'con el panel abierto no sale de la caché');

    $app->invalidate(['thing:5']);
    same('MISS', aifecFrontGet($i, '/cosa/silla')->headers['X-Cache'], 'tras invalidar la etiqueta se vuelve a generar');

    $home = aifecFrontGet($i, '/');
    check(!isset($home->headers['X-Cache']), 'lo que no se marca como cacheable no se guarda');
});

test('recursos estáticos: se sirven los permitidos, nunca PHP ni rutas con «..»', function (): void {
    $i = aifecFrontInstall();
    $r = aifecFrontGet($i, '/assets/t/base/dev/css/a.css');
    same(200, $r->status);
    same('text/css; charset=UTF-8', $r->headers['Content-Type']);
    check($r->file !== null && is_file($r->file));
    same(304, aifecFrontGet($i, '/assets/t/base/dev/css/a.css', ['if-none-match' => $r->headers['ETag']])->status);
    same(200, aifecFrontGet($i, '/assets/m/demo/dev/m.js')->status);
    same(404, aifecFrontGet($i, '/assets/t/base/dev/secreto.php')->status, 'un .php nunca se sirve como recurso');
    same(null, AssetServer::locate($i[0], 't/base/dev/../theme.json'));
    same(null, AssetServer::locate($i[0], 't/base/dev/css/../../theme.json'));
    same(404, aifecFrontGet($i, '/assets/t/base/dev/no.css')->status);

    $published = AssetServer::publish($i[0], $i[2]);
    same(2, $published['files'], 'se publican el CSS y el JS, no el PHP');
    check(is_file($i[2] . '/assets/t/base/' . $published['build'] . '/css/a.css'));
    same($published['build'], trim((string) file_get_contents($i[2] . '/assets/build.txt')));
});

test('registro de visitas: robots y asistentes sí, personas no, y sin datos personales', function (): void {
    $i = aifecFrontInstall();
    aifecFrontGet($i, '/', ['user-agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)']);
    aifecFrontGet($i, '/?utm_source=chatgpt.com', ['user-agent' => 'Mozilla/5.0 Chrome/140']);
    aifecFrontGet($i, '/', ['user-agent' => 'Mozilla/5.0 Chrome/140', 'referer' => 'https://www.perplexity.ai/search?q=x']);
    aifecFrontGet($i, '/', ['user-agent' => 'Mozilla/5.0 Chrome/140']);
    $files = glob($i[1] . '/main/logs/visits-*.tsv');
    same(1, count((array) $files));
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($files[0]))));
    same(3, count($lines), 'la visita de una persona sin origen de IA no se apunta');
    contains("\tbot\tOAI-SearchBot\t200\t", $lines[0]);
    contains("\tref\tChatGPT\t", $lines[1]);
    contains("\tref\tPerplexity\t", $lines[2]);
    same(['Googlebot', 'Google', 'search'], VisitLog::bot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
    same(null, VisitLog::bot('Mozilla/5.0 Firefox/140'));
});

test('consola: órdenes del núcleo y de los módulos, por sitio', function (): void {
    $i = aifecFrontInstall();
    $io = new Console(false);
    same(0, $io->run(['aifec', 'sites'], $i[0], $i[1]));
    contains('moda', $io->output);
    contains('/demos/moda', $io->output);
    $io = new Console(false);
    same(0, $io->run(['aifec', 'demo:hola', 'Ana', '--site=moda', '--alto'], $i[0], $i[1]));
    contains('hola Ana en moda!', $io->output);
    $io = new Console(false);
    same(2, $io->run(['aifec', 'no-existe'], $i[0], $i[1]));
    same(2, (new Console(false))->run(['aifec', 'migrate', '--site=fantasma'], $i[0], $i[1]));
    $io = new Console(false);
    same(0, $io->run(['aifec', 'help'], $i[0], $i[1]));
    contains('demo:hola', $io->output);
    contains('Says hello', $io->output);
    same(['x', 'limit' => '5', 'force' => true], Console::parse(['x', '--limit=5', '--force']));
});
