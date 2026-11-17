<?php
/**
 * AIFEC — la IA en los textos de los correos: proponer (redactar o reescribir) y traducir a los demás idiomas, siempre como propuesta.
 *
 * Todo con una IA de mentira: ninguna prueba habla con un proveedor ni con un servidor. Fallos que cubre: la propuesta se guarda sola,
 * una variable inventada pasa, sin el módulo de IA sale el botón, no se respeta el permiso y se traduce encima de un idioma que ya
 * tenía textos sin marcarlo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiResult;
use Aifec\Ai\FakeProvider;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Mail\MailAi;
use Aifec\Modules\Mail\Mailer;

require_once __DIR__ . '/MailKit.php';

if (!function_exists('mailAiApp')) {
    /** Un proveedor que siempre falla y que, como los de verdad, repite la clave en su mensaje. */
    final class MailAiFailingProvider implements AiProvider
    {
        /** @var string */
        private $message;

        public function __construct(string $message)
        {
            $this->message = $message;
        }

        public function name(): string
        {
            return 'failing';
        }

        public function generate(AiRequest $r): AiResult
        {
            throw new AiException($this->message, true, 503);
        }

        public function embed(array $texts, string $model = ''): array
        {
            throw new AiException($this->message, true, 503);
        }
    }

    /**
     * Aplicación con el correo y (si se pide) el módulo de IA, con un proveedor simulado en el nivel económico y dos idiomas.
     *
     * @param array<string,mixed> $settings
     * @param string[]            $languages
     *
     * @return array{0:App,1:FakeProvider}
     */
    function mailAiApp(array $settings = [], bool $withAi = true, bool $bindFake = true, array $languages = ['es', 'en'], ?AiProvider $provider = null): array
    {
        $app = testApp(['modules' => $withAi ? ['ai', 'mail'] : ['mail'], 'settings' => $settings + [
            'mail.mode' => 'live', 'mail.from_email' => 'tienda@tienda.test', 'i18n.languages' => $languages,
            'ai.enabled' => true, 'ai.daily_limit_cents' => 200, 'ai.economy.price_in' => 100, 'ai.economy.price_out' => 400,
        ]]);
        $app->hooks()->filter('mail.templates', static function (array $templates): array {
            $templates['plugin_demo'] = [
                'subject' => 'Your code {code} for {shop}', 'view' => 'mail::emails/mail_test', 'module' => 'un-plugin', 'system' => false,
                'description' => 'A template added by a plugin', 'sample' => ['code' => 'X1', 'lines' => [['name' => 'a']], 'name' => 'Ana', 'contact' => 'ana@cliente.test'],
            ];

            return $templates;
        });
        $app->boot();
        $fake = new FakeProvider();
        if ($withAi && $bindFake) {
            $use = $provider !== null ? $provider : $fake;
            $app->bind('ai.provider.economy', static function () use ($use): AiProvider {
                return $use;
            });
        }

        return [$app, $fake];
    }

    /** Una respuesta con JSON válido, como la que da un modelo cuando se le pidió un esquema. */
    function mailAiJson(array $data): AiResult
    {
        return new AiResult((string) json_encode($data, JSON_UNESCAPED_UNICODE), [], $data, 100, 50, 'stop', 'fake-model');
    }

    /** Envía un formulario con la ficha de la pantalla de edición recién abierta. */
    function mailAiPost(TestClient $web, string $path, array $fields, string $lang = 'es', string $key = 'admin_reset'): Response
    {
        $web->get('/admin/mail/templates/' . $key . '/edit?lang=' . $lang);

        return $web->post('/admin/mail/templates/' . $key . $path, ['_token' => aifecToken($web), 'lang' => $lang] + $fields);
    }

    function mailAiService(App $app): Mailer
    {
        $service = $app->service('mailer');
        check($service instanceof Mailer, 'el servicio mailer es el del módulo');

        return $service;
    }

    /** @return array<int,array<string,mixed>> */
    function mailAiUsage(App $app): array
    {
        return $app->db()->select('SELECT task, tier, ok, error FROM {ai_usage} ORDER BY id');
    }

    /** Todo lo que se le ha mandado a la IA de mentira, en un solo texto. */
    function mailAiSent(FakeProvider $fake): string
    {
        $all = '';
        foreach ($fake->requests as $request) {
            $all .= $request->system . "\n";
            foreach ($request->messages as $message) {
                $all .= $message->content . "\n";
            }
        }

        return $all;
    }

    /** Un papel con solo estos permisos, y la sesión abierta con él. */
    function mailAiRole(App $app, string $role, array $caps): TestClient
    {
        $web = adminClient($app, $role);
        $app->db()->update('roles', ['caps' => json_encode($caps)], ['code' => $role]);
        $app->auth()->reset();
        $web->get('/admin');

        return $web;
    }
}

const MAIL_AI_PROPOSAL = ['subject' => 'Tu nueva clave de {shop}', 'greeting' => 'Buenas, {name}', 'farewell' => 'Un saludo del equipo de {shop}'];
const MAIL_AI_ROUTES = ['/ai/propose', '/ai/translate', '/ai/translate/save'];

// ---------------------------------------------------------------------- sin IA, nada cambia

test('sin el módulo de IA la pantalla de edición no enseña ni el cuadro ni los botones', function (): void {
    [$app] = mailAiApp([], false);
    $page = adminClient($app)->get('/admin/mail/templates/admin_reset/edit');
    same(200, $page->status);
    notContains('data-mail-ai', $page->body);
    notContains('Proponer con IA', $page->body);
    notContains('Traducir a los demás idiomas', $page->body);
    contains('Vista previa sin guardar', $page->body, 'lo de siempre sigue');
});

test('sin el módulo de IA las tres rutas de la IA dan 404 y no cambian nada', function (): void {
    [$app] = mailAiApp([], false);
    $web = adminClient($app);
    foreach (MAIL_AI_ROUTES as $path) {
        same(404, mailAiPost($web, $path, ['subject' => 'x', 'tone' => 'formal'])->status, $path);
    }
    same([], $app->settings()->arr('mail.texts'));
    check(!$app->has('ai'), 'el módulo de IA no está cargado');
});

test('el módulo de correo no exige el de IA: su ficha no lo declara y el servicio «mailer» arranca sin él', function (): void {
    $json = json_decode((string) file_get_contents(__DIR__ . '/../module.json'), true);
    check(is_array($json) && !isset($json['requires']), 'sin «requires»');
    [$app] = mailAiApp([], false);
    check(mailAiService($app) instanceof Mailer);
    check(!MailAi::available($app));
});

test('con la IA apagada no sale ni el cuadro ni el botón, y las rutas dan 404', function (): void {
    [$app] = mailAiApp(['ai.enabled' => false]);
    $web = adminClient($app);
    $page = $web->get('/admin/mail/templates/admin_reset/edit');
    notContains('data-mail-ai', $page->body);
    notContains('Proponer con IA', $page->body);
    same(404, mailAiPost($web, '/ai/propose', ['subject' => 'x'])->status);
    same(404, mailAiPost($web, '/ai/translate', [])->status);
});

test('con la IA encendida pero sin proveedor ni clave tampoco sale nada', function (): void {
    [$app] = mailAiApp([], true, false);
    $web = adminClient($app);
    notContains('data-mail-ai', $web->get('/admin/mail/templates/admin_reset/edit')->body);
    same(404, mailAiPost($web, '/ai/propose', ['subject' => 'x'])->status);
    $app->setSecret('ai.economy.key', 'clave-de-mentira');
    $app->settings()->reload();
    check(MailAi::ready($app), 'con una clave ya está lista');
});

test('con la IA lista sale el cuadro con el tono y los dos botones, que mandan el formulario a las rutas de la IA', function (): void {
    [$app] = mailAiApp();
    $page = adminClient($app)->get('/admin/mail/templates/admin_reset/edit');
    contains('data-mail-ai', $page->body);
    contains('name="tone"', $page->body);
    contains('maxlength="200"', $page->body);
    contains('Proponer con IA', $page->body);
    contains('Traducir a los demás idiomas', $page->body);
    contains('formaction="' . $app->url()->plain('/admin/mail/templates/admin_reset/ai/propose') . '"', $page->body);
    contains('formaction="' . $app->url()->plain('/admin/mail/templates/admin_reset/ai/translate') . '"', $page->body);
    contains('Cercano', $page->body, 'los tonos de ejemplo');
    notContains('AI proposal', $page->body);
    notContains('Propuesta de la IA', $page->body, 'sin propuesta no hay marca de propuesta');
});

test('con un solo idioma no sale el botón de traducir', function (): void {
    [$app] = mailAiApp([], true, true, ['es']);
    $page = adminClient($app)->get('/admin/mail/templates/admin_reset/edit');
    contains('Proponer con IA', $page->body);
    notContains('Traducir a los demás idiomas', $page->body);
});

test('sin el permiso «ai.use» (aunque pueda editar el correo) no hay cuadro y las tres rutas dan 403', function (): void {
    [$app, $fake] = mailAiApp();
    $web = mailAiRole($app, 'support', ['dashboard.view', 'mail.view', 'mail.edit']);
    $page = $web->get('/admin/mail/templates/admin_reset/edit');
    same(200, $page->status, 'editar sí');
    notContains('data-mail-ai', $page->body);
    foreach (MAIL_AI_ROUTES as $path) {
        same(403, mailAiPost($web, $path, ['subject' => 'x'])->status, $path);
    }
    same([], $fake->requests, 'ni una llamada a la IA');
    same([], mailAiUsage($app));
});

test('con «ai.use» pero sin «mail.edit» la pantalla y las rutas dan 403', function (): void {
    [$app, $fake] = mailAiApp();
    $web = mailAiRole($app, 'editor', ['dashboard.view', 'mail.view', 'ai.use']);
    same(403, $web->get('/admin/mail/templates/admin_reset/edit')->status);
    foreach (MAIL_AI_ROUTES as $path) {
        $web->get('/admin');
        same(403, $web->post('/admin/mail/templates/admin_reset' . $path, ['_token' => aifecToken($web), 'subject' => 'x'])->status, $path);
    }
    same([], $fake->requests);
});

// ---------------------------------------------------------------------- proponer

test('proponer rellena los tres campos, enseña la marca de propuesta y la vista previa montada, y no guarda nada', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/propose', ['subject' => '', 'greeting' => '', 'farewell' => '', 'tone' => 'cercano']);
    same(200, $r->status);
    contains('value="Tu nueva clave de {shop}"', $r->body);
    contains('Buenas, {name}', $r->body);
    contains('Un saludo del equipo de {shop}', $r->body);
    contains('data-mail-ai-proposal', $r->body);
    contains('Propuesta de la IA: revísala y guarda.', $r->body);
    contains('Vista previa con los datos de ejemplo', $r->body);
    contains('<iframe sandbox srcdoc="', $r->body);
    same([], $app->settings()->arr('mail.texts'), 'la propuesta no se guarda sola');
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], mailAiService($app)->texts('admin_reset', 'es'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'), 'ni se envía nada');
});

test('la vista previa de una propuesta usa los textos propuestos con los datos de ejemplo', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    $r = mailAiPost(adminClient($app), '/ai/propose', ['tone' => 'formal']);
    contains('Tu nueva clave de AIFEC', $r->body, 'el asunto con la tienda rellena');
    contains('Buenas, Ana', $r->body, 'el saludo con el nombre de ejemplo');
    contains('Un saludo del equipo de AIFEC', $r->body);
});

test('guardar la propuesta es otro paso: el formulario normal la guarda, y entonces sí sale en el correo', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    $web = adminClient($app);
    mailAiPost($web, '/ai/propose', []);
    same([], $app->settings()->arr('mail.texts'));
    $r = mailAiPost($web, '/edit', ['action' => 'save'] + MAIL_AI_PROPOSAL);
    same(303, $r->status);
    same(MAIL_AI_PROPOSAL, mailAiService($app)->texts('admin_reset', 'es'));
});

test('proponer reescribe lo que hay escrito: los textos del formulario viajan como «actuales»', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    mailAiPost(adminClient($app), '/ai/propose', ['subject' => 'Mi asunto con {shop}', 'greeting' => 'Qué tal, {name}', 'farewell' => 'Hasta luego', 'tone' => 'breve']);
    $sent = mailAiSent($fake);
    contains('Current subject: Mi asunto con {shop}', $sent);
    contains('Current greeting: Qué tal, {name}', $sent);
    contains('Current sign-off: Hasta luego', $sent);
    contains('Original subject: Elige una contraseña nueva para {shop}', $sent, 'y los de serie');
    contains('Original greeting: Hola {name}', $sent);
    contains('Language to write in: Spanish', $sent);
});

test('a la IA se le dice el correo, cuándo sale, la tienda, el idioma, el tono y la lista de variables permitidas', function (): void {
    [$app, $fake] = mailAiApp(['store.name' => 'Tienda de Pruebas']);
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    mailAiPost(adminClient($app), '/ai/propose', ['tone' => 'Cercano y con humor'], 'en');
    $sent = mailAiSent($fake);
    contains('Email: admin_reset', $sent);
    contains('When it is sent: Link to choose a new panel password', $sent);
    contains('Shop name: Tienda de Pruebas', $sent);
    contains('Language to write in: English', $sent);
    contains('Tone: Cercano y con humor', $sent);
    contains('Variables you may use: {shop}, {name} (example: Ana), {url}', $sent);
    contains('{minutes} (example: 60)', $sent);
    check(isset($fake->requests[0]->jsonSchema) && $fake->requests[0]->jsonSchema !== null, 'pide JSON con esquema');
});

test('el tono se corta a 200 caracteres, va dentro de la cerca de datos y no en la instrucción del sistema', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    $long = str_repeat('muy cercano ', 40);
    mailAiPost(adminClient($app), '/ai/propose', ['tone' => $long]);
    $request = $fake->requests[0];
    notContains('muy cercano', $request->system, 'la instrucción no lleva datos');
    $body = $request->messages[0]->content;
    contains('<<<DATA EMAIL ', $body);
    preg_match('/Tone: ([^\n]*)/', $body, $m);
    same(200, mb_strlen($m[1]), 'el tono llega recortado a 200');
    same('clear and friendly', MailAi::tone('   '), 'sin tono, el de siempre');
    same(200, mb_strlen(MailAi::tone($long)));
    same('una línea dos', MailAi::tone("una línea\n\tdos"), 'una sola línea');
});

test('lo que viaja a la IA no lleva ningún dato de un cliente: ni su correo ni su nombre ni los datos de la plantilla de otros', function (): void {
    [$app, $fake] = mailAiApp();
    $mailer = mailAiService($app);
    $mailer->queue('admin_reset', 'marta@cliente.test', ['name' => 'Marta Cliente', 'url' => 'https://tienda.test/reset/secreto', 'minutes' => 30], ['to_name' => 'Marta Cliente']);
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL), mailAiJson(MAIL_AI_PROPOSAL));
    $web = adminClient($app);
    mailAiPost($web, '/ai/propose', ['tone' => 'formal']);
    $mailer->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, gracias']);
    mailAiPost($web, '/ai/translate', []);
    $sent = mailAiSent($fake);
    notContains('marta@cliente.test', $sent);
    notContains('Marta Cliente', $sent);
    notContains('secreto', $sent);
    notContains('admin@tienda.test', $sent, 'ni el correo de quien pulsa');
    contains('(example: Ana)', $sent, 'solo datos de ejemplo');
});

test('un dato de ejemplo con una arroba no viaja a la IA', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Código {code} de {shop}', 'greeting' => '', 'farewell' => '']));
    mailAiPost(adminClient($app), '/ai/propose', [], 'es', 'plugin_demo');
    $sent = mailAiSent($fake);
    notContains('ana@cliente.test', $sent);
    contains('{contact}', $sent, 'la variable sí se nombra');
    contains('{code} (example: X1)', $sent);
});

test('una variable inventada: ese campo se descarta con un aviso y los demás campos sí se usan', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Tu pedido {order_number} de {shop}', 'greeting' => 'Buenas, {name}', 'farewell' => 'Gracias']));
    $r = mailAiPost(adminClient($app), '/ai/propose', []);
    same(200, $r->status);
    contains('La propuesta de «Asunto» usaba {order_number}, que esta plantilla no tiene: no se ha usado', $r->body);
    notContains('Tu pedido {order_number}', $r->body, 'el asunto no se rellena');
    contains('value=""', $r->body);
    contains('Buenas, {name}', $r->body);
    contains('Gracias', $r->body);
    contains('af-alert-warn', $r->body);
});

test('una variable perdida (la del texto de partida): el campo se descarta con un aviso', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Elige tu clave', 'greeting' => 'Buenas, {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/propose', []);
    contains('La propuesta de «Asunto» cambiaba {shop}: no se ha usado', $r->body);
    notContains('Elige tu clave', $r->body);
    contains('Buenas, {name}', $r->body);
});

test('la variable perdida se mira contra el texto de partida escrito, no solo contra el de serie', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Clave nueva', 'greeting' => 'Hola', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/propose', ['subject' => 'Clave {minutes} de {shop}', 'greeting' => 'Qué tal {name}']);
    contains('La propuesta de «Asunto» cambiaba {minutes}, {shop}: no se ha usado', $r->body);
    contains('La propuesta de «Saludo» cambiaba {name}: no se ha usado', $r->body);
    contains('value="Clave {minutes} de {shop}"', $r->body, 'el formulario conserva lo que había');
    contains('El formulario se queda como estaba', $r->body, 'sin nada aprovechable lo dice');
});

test('las llaves que no son variables ({{name}} o {name } con espacios) descartan el campo', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Clave de {{shop}}', 'greeting' => 'Hola { name }', 'farewell' => 'Adiós {name}']));
    $r = mailAiPost(adminClient($app), '/ai/propose', []);
    notContains('{{shop}}', $r->body);
    notContains('{ name }', $r->body);
    contains('Adiós {name}', $r->body);
    contains('lleva llaves que no son variables', $r->body);
});

test('el HTML de la propuesta se quita: etiquetas fuera y entidades descodificadas', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => '<b>Tu clave</b> de {shop}', 'greeting' => '<p>Buenas, <a href="https://malo.test">{name}</a></p>', 'farewell' => 'Equipo &amp; <em>amigos</em> &lt;b&gt;']));
    $r = mailAiPost(adminClient($app), '/ai/propose', []);
    contains('value="Tu clave de {shop}"', $r->body);
    contains('Buenas, {name}', $r->body);
    notContains('malo.test', $r->body);
    notContains('<b>Tu clave', $r->body);
    contains('Equipo &amp; amigos', $r->body, 'el «&» llega como texto y la pantalla lo escapa una sola vez');
    notContains('amigos</em>', $r->body);
});

test('un asunto de dos líneas queda en una, y uno de más de 191 caracteres se corta', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => "Tu clave\nde {shop}\r\n y más", 'greeting' => '', 'farewell' => '']));
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/propose', []);
    contains('value="Tu clave de {shop} y más"', $r->body);
    $fake->script(mailAiJson(['subject' => 'Clave {shop} ' . str_repeat('a', 400), 'greeting' => '', 'farewell' => '']));
    $r = mailAiPost($web, '/ai/propose', []);
    preg_match('/id="mt-subject" type="text" name="subject" value="([^"]*)"/', $r->body, $m);
    same(191, mb_strlen($m[1]));
    check(strpos($m[1], "\n") === false, 'sin saltos');
});

test('una respuesta sin la forma pedida avisa, deja el formulario intacto y se apunta como fallo en el registro de uso', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['otra' => 'cosa']));
    $r = mailAiPost(adminClient($app), '/ai/propose', ['subject' => 'Lo que escribí', 'greeting' => 'Y esto']);
    same(200, $r->status);
    contains('La IA no ha podido contestar', $r->body);
    contains('value="Lo que escribí"', $r->body);
    contains('Y esto', $r->body);
    notContains('data-mail-ai-proposal', $r->body);
    $usage = mailAiUsage($app);
    same(1, count($usage));
    same(0, (int) $usage[0]['ok'], 'la llamada salió bien pero la respuesta no valía: fallo');
});

test('con la IA caída avisa sin enseñar la clave y el formulario queda intacto', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en'], new MailAiFailingProvider('503 de la IA con la clave clave-de-mentira dentro'));
    $app->setSecret('ai.economy.key', 'clave-de-mentira');
    $r = mailAiPost(adminClient($app), '/ai/propose', ['subject' => 'Mi asunto {shop}']);
    same(200, $r->status);
    contains('La IA no ha podido contestar', $r->body);
    notContains('clave-de-mentira', $r->body);
    contains('value="Mi asunto {shop}"', $r->body);
    same([], $app->settings()->arr('mail.texts'));
    $usage = mailAiUsage($app);
    same([0], array_map('intval', array_column($usage, 'ok')), 'el fallo queda apuntado');
});

test('con el tope del día agotado avisa con un mensaje claro, no llama a la IA y no cambia nada', function (): void {
    [$app, $fake] = mailAiApp(['ai.daily_limit_cents' => 0]);
    $r = mailAiPost(adminClient($app), '/ai/propose', ['subject' => 'Mi asunto {shop}']);
    same(200, $r->status);
    contains('Se ha llegado al tope diario de la IA', $r->body);
    contains('value="Mi asunto {shop}"', $r->body);
    same([], $fake->requests, 'ni una llamada al proveedor');
    same([], $app->settings()->arr('mail.texts'));
});

test('el uso queda en el registro del módulo de IA con su tarea, su nivel y el resultado', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(MAIL_AI_PROPOSAL));
    mailAiPost(adminClient($app), '/ai/propose', []);
    $usage = mailAiUsage($app);
    same(1, count($usage));
    same('mail.texts', $usage[0]['task']);
    same('economy', $usage[0]['tier']);
    same(1, (int) $usage[0]['ok']);
    $audit = $app->db()->select("SELECT action FROM {audit} WHERE entity = 'mail'");
    check(in_array('mail.ai', array_column($audit, 'action'), true), 'y queda en la auditoría');
});

test('proponer en la plantilla de un plugin usa sus variables y sus datos de ejemplo', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Su código {code} para {shop}', 'greeting' => 'Hola {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/propose', [], 'es', 'plugin_demo');
    contains('value="Su código {code} para {shop}"', $r->body);
    contains('Su código X1 para AIFEC', $r->body, 'la vista previa con el código de ejemplo');
});

test('un asunto con «&», comillas, tildes y emojis pasa tal cual y se escapa una sola vez', function (): void {
    [$app, $fake] = mailAiApp();
    $fake->script(mailAiJson(['subject' => 'Ñandú & "Cía" 😀 {shop}', 'greeting' => '', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/propose', []);
    contains('value="Ñandú &amp; &quot;Cía&quot; 😀 {shop}"', $r->body);
});

// ---------------------------------------------------------------------- traducir

test('traducir sin textos guardados en el idioma avisa y no llama a la IA', function (): void {
    [$app, $fake] = mailAiApp();
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    same(303, $r->status);
    same([], $fake->requests);
});

test('traducir propone para cada idioma que no es el de partida, enseña original y propuesta lado a lado y no guarda nada', function (): void {
    [$app, $fake] = mailAiApp([], true, true, ['es', 'en', 'fr']);
    $mailer = mailAiService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => 'Tu clave nueva de {shop}', 'greeting' => 'Buenas, {name}']);
    $fake->script(
        mailAiJson(['subject' => 'Your new key at {shop}', 'greeting' => 'Hi, {name}', 'farewell' => '']),
        mailAiJson(['subject' => 'Votre nouvelle clé chez {shop}', 'greeting' => 'Bonjour, {name}', 'farewell' => ''])
    );
    $before = $app->settings()->arr('mail.texts');
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    same(200, $r->status);
    same(2, count($fake->requests), 'una petición por idioma de destino');
    contains('Translate from Spanish into English', mailAiSent($fake));
    contains('Translate from Spanish into French', mailAiSent($fake));
    contains('data-mail-translate-lang="en"', $r->body);
    contains('data-mail-translate-lang="fr"', $r->body);
    contains('Tu clave nueva de {shop}', $r->body, 'el original');
    contains('value="Your new key at {shop}"', $r->body, 'la propuesta en inglés');
    contains('value="Votre nouvelle clé chez {shop}"', $r->body, 'y en francés');
    contains('Original', $r->body);
    contains('Propuesta', $r->body);
    same($before, $app->settings()->arr('mail.texts'), 'traducir no guarda');
    $tasks = array_column(mailAiUsage($app), 'task');
    same(['mail.translate', 'mail.translate'], $tasks);
});

test('traducir solo manda a la IA los campos que traen texto guardado', function (): void {
    [$app, $fake] = mailAiApp();
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Buenas, {name}']);
    $fake->script(mailAiJson(['subject' => 'Inventado {shop}', 'greeting' => 'Hi, {name}', 'farewell' => 'Inventada']));
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    $sent = mailAiSent($fake);
    contains('Source greeting: Buenas, {name}', $sent);
    contains('Source subject: (none)', $sent);
    notContains('Inventado', $r->body, 'lo que se inventa en un campo vacío no se enseña');
    contains('>Hi, {name}</textarea>', $r->body);
});

test('un idioma que ya tiene textos propios sale desmarcado y el que no los tiene, marcado', function (): void {
    [$app, $fake] = mailAiApp([], true, true, ['es', 'en', 'fr']);
    $mailer = mailAiService($app);
    $mailer->saveTexts('admin_reset', 'es', ['greeting' => 'Buenas, {name}']);
    $mailer->saveTexts('admin_reset', 'en', ['greeting' => 'Hi there {name}']);
    $fake->script(mailAiJson(['subject' => '', 'greeting' => 'Hello, {name}', 'farewell' => '']), mailAiJson(['subject' => '', 'greeting' => 'Salut, {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    contains('name="apply[en]" value="1">', $r->body, 'el inglés tenía textos: desmarcado');
    contains('name="apply[fr]" value="1" checked>', $r->body, 'el francés no: marcado');
    contains('Hi there {name}', $r->body, 'y se enseña lo que hay ahora');
    contains('Este idioma ya tiene textos propios', $r->body);
});

test('guardar las traducciones guarda solo los idiomas marcados', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en', 'fr']);
    $mailer = mailAiService($app);
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/translate/save', [
        'apply' => ['fr' => '1'],
        't' => [
            'en' => ['subject' => 'English subject {shop}', 'greeting' => 'Hi {name}', 'farewell' => ''],
            'fr' => ['subject' => 'Sujet {shop}', 'greeting' => 'Salut {name}', 'farewell' => 'À bientôt'],
        ],
    ]);
    same(303, $r->status);
    same(['subject' => 'Sujet {shop}', 'greeting' => 'Salut {name}', 'farewell' => 'À bientôt'], $mailer->texts('admin_reset', 'fr'));
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], $mailer->texts('admin_reset', 'en'), 'el inglés no estaba marcado');
    contains('Guardados los textos de: FR', $web->follow()->body);
});

test('un idioma con textos propios no se pisa si no está marcado, aunque venga en el envío', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en']);
    $mailer = mailAiService($app);
    $mailer->saveTexts('admin_reset', 'en', ['greeting' => 'Mi saludo propio {name}', 'subject' => 'Mi asunto propio']);
    $web = adminClient($app);
    mailAiPost($web, '/ai/translate/save', ['t' => ['en' => ['subject' => 'Pisado {shop}', 'greeting' => 'Pisado {name}', 'farewell' => 'Pisada']]]);
    same(['subject' => 'Mi asunto propio', 'greeting' => 'Mi saludo propio {name}', 'farewell' => ''], $mailer->texts('admin_reset', 'en'));
    contains('No se ha marcado nada', $web->follow()->body);
});

test('marcado un idioma con textos propios, los campos que la propuesta dejó vacíos conservan lo que había', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en']);
    $mailer = mailAiService($app);
    $mailer->saveTexts('admin_reset', 'en', ['greeting' => 'Mi saludo propio {name}', 'farewell' => 'Mi despedida']);
    mailAiPost(adminClient($app), '/ai/translate/save', ['apply' => ['en' => '1'], 't' => ['en' => ['subject' => 'Nuevo asunto {shop}', 'greeting' => '', 'farewell' => '']]]);
    same(['subject' => 'Nuevo asunto {shop}', 'greeting' => 'Mi saludo propio {name}', 'farewell' => 'Mi despedida'], $mailer->texts('admin_reset', 'en'));
});

test('guardar con idiomas que no existen en la tienda o con el de partida no guarda nada', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en']);
    $mailer = mailAiService($app);
    mailAiPost(adminClient($app), '/ai/translate/save', ['apply' => ['es' => '1', 'xx' => '1', 'en' => ''], 't' => ['es' => ['subject' => 'Cambiado {shop}'], 'xx' => ['subject' => 'Raro {shop}']]]);
    same([], $app->settings()->arr('mail.texts'), 'ni el de partida ni uno desconocido');
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], $mailer->texts('admin_reset', 'es'));
});

test('guardar un texto traducido con una variable que la plantilla no tiene lo avisa, como el formulario normal', function (): void {
    [$app] = mailAiApp();
    $web = adminClient($app);
    mailAiPost($web, '/ai/translate/save', ['apply' => ['en' => '1'], 't' => ['en' => ['subject' => 'Key {pedido}']]]);
    $page = $web->follow();
    contains('Estas variables no existen en esta plantilla', $page->body);
    contains('{pedido}', $page->body);
});

test('en una traducción, una variable inventada descarta ese campo con aviso y no los demás', function (): void {
    [$app, $fake] = mailAiApp();
    mailAiService($app)->saveTexts('admin_reset', 'es', ['subject' => 'Tu clave de {shop}', 'greeting' => 'Hola {name}, buenas']);
    $fake->script(mailAiJson(['subject' => 'Your key at {shop} for {order_number}', 'greeting' => 'Hi {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    contains('La propuesta de «Asunto» usaba {order_number}', $r->body);
    notContains('for {order_number}', $r->body);
    contains('>Hi {name}</textarea>', $r->body);
});

test('en una traducción, una variable perdida descarta ese campo con aviso', function (): void {
    [$app, $fake] = mailAiApp();
    mailAiService($app)->saveTexts('admin_reset', 'es', ['subject' => 'Tu clave de {shop} en {minutes} minutos', 'greeting' => 'Hola {name}, buenas']);
    $fake->script(mailAiJson(['subject' => 'Your key at {shop}', 'greeting' => 'Hi {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    contains('La propuesta de «Asunto» cambiaba {minutes}: no se ha usado', $r->body);
    notContains('Your key at', $r->body);
    contains('>Hi {name}</textarea>', $r->body);
});

test('si todas las traducciones se descartan, avisa y vuelve a la edición sin guardar nada', function (): void {
    [$app, $fake] = mailAiApp();
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, buenas']);
    $before = $app->settings()->arr('mail.texts');
    $fake->script(mailAiJson(['subject' => '', 'greeting' => 'Hi', 'farewell' => '']));
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/translate', []);
    same(303, $r->status);
    $page = $web->follow();
    contains('cambiaba {name}', $page->body);
    same($before, $app->settings()->arr('mail.texts'));
});

test('traducir con la IA caída avisa y nada cambia', function (): void {
    [$app] = mailAiApp([], true, true, ['es', 'en'], new MailAiFailingProvider('504 de la IA con la clave clave-de-mentira dentro'));
    $app->setSecret('ai.economy.key', 'clave-de-mentira');
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, buenas']);
    $before = $app->settings()->arr('mail.texts');
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/translate', []);
    same(303, $r->status);
    $page = $web->follow();
    contains('La IA no ha podido contestar', $page->body);
    notContains('clave-de-mentira', $page->body);
    same($before, $app->settings()->arr('mail.texts'));
});

test('traducir con el tope del día agotado avisa y no hace ninguna llamada', function (): void {
    [$app, $fake] = mailAiApp(['ai.daily_limit_cents' => 0], true, true, ['es', 'en', 'fr']);
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, buenas']);
    $web = adminClient($app);
    $r = mailAiPost($web, '/ai/translate', []);
    same(303, $r->status);
    contains('Se ha llegado al tope diario de la IA', $web->follow()->body);
    same([], $fake->requests);
    same([], mailAiUsage($app), 'sin llamada no hay uso');
});

test('si un idioma falla pero otro sale, se enseña el que salió y el error del otro', function (): void {
    [$app, $fake] = mailAiApp([], true, true, ['es', 'en', 'fr']);
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, buenas']);
    $fake->script(mailAiJson(['otra' => 'cosa']), mailAiJson(['subject' => '', 'greeting' => 'Salut {name}', 'farewell' => '']));
    $r = mailAiPost(adminClient($app), '/ai/translate', []);
    same(200, $r->status);
    contains('>Salut {name}</textarea>', $r->body);
    contains('La IA no ha podido contestar', $r->body);
    contains('name="apply[fr]"', $r->body);
    notContains('name="apply[en]"', $r->body, 'sin propuesta no hay casilla');
});

test('las rutas de traducir y guardar piden ficha: sin ella no hacen nada', function (): void {
    [$app, $fake] = mailAiApp();
    mailAiService($app)->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}, buenas']);
    $web = adminClient($app);
    $web->get('/admin/mail/templates/admin_reset/edit');
    $r = $web->post('/admin/mail/templates/admin_reset/ai/translate/save', ['apply' => ['en' => '1'], 't' => ['en' => ['subject' => 'x {shop}']]]);
    same(302, $r->status, 'sin ficha se rechaza y vuelve atrás');
    $r = $web->post('/admin/mail/templates/admin_reset/ai/propose', ['subject' => 'x']);
    same(302, $r->status, 'sin ficha se rechaza y vuelve atrás');
    same([], $fake->requests);
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], mailAiService($app)->texts('admin_reset', 'en'));
});

test('una plantilla que no existe da 404 en las tres rutas', function (): void {
    [$app] = mailAiApp();
    $web = adminClient($app);
    foreach (MAIL_AI_ROUTES as $path) {
        $web->get('/admin/mail/templates');
        same(404, $web->post('/admin/mail/templates/no_existe' . $path, ['_token' => aifecToken($web)])->status, $path);
    }
});

// ---------------------------------------------------------------------- las piezas sueltas

test('MailAi::check deja pasar lo bueno y cuenta como «de partida» las variables permitidas del texto de origen', function (): void {
    [$app] = mailAiApp();
    $ai = new MailAi($app, mailAiService($app));
    $allowed = ['shop', 'name'];
    $ok = $ai->check(['subject' => 'Hola {name} de {shop}', 'greeting' => '', 'farewell' => ''], ['subject' => 'Bienvenida {name}', 'greeting' => '', 'farewell' => ''], $allowed, MailAi::FIELDS);
    same('Hola {name} de {shop}', $ok['texts']['subject']);
    same([], $ok['warnings']);
    $lost = $ai->check(['subject' => 'Hola {shop}'], ['subject' => 'Bienvenida {name}', 'greeting' => '', 'farewell' => ''], $allowed, MailAi::FIELDS);
    same('', $lost['texts']['subject']);
    same(1, count($lost['warnings']));
    $unknownInBase = $ai->check(['subject' => 'Hola'], ['subject' => 'Bienvenida {inventada}', 'greeting' => '', 'farewell' => ''], $allowed, MailAi::FIELDS);
    same('Hola', $unknownInBase['texts']['subject'], 'una variable que la plantilla no tiene no se exige');
    $skipped = $ai->check(['subject' => 'Algo', 'greeting' => 'Otra cosa'], ['subject' => '', 'greeting' => '', 'farewell' => ''], $allowed, ['greeting']);
    same(['subject' => '', 'greeting' => 'Otra cosa', 'farewell' => ''], $skipped['texts'], 'solo los campos pedidos');
    same(['a', 'b', 'd'], MailAi::variablesIn('{a} y {b} y {a} { c } {{d}}x'), 'las variables distintas, en orden');
});

test('los textos nuevos están en el catálogo castellano y las cadenas de ayuda no llevan «%s%%»', function (): void {
    $es = require __DIR__ . '/../lang/es.php';
    foreach (['Propose with AI', 'Translate to the other languages', 'AI proposal: check it and save.', 'Tone', 'Save the ticked languages',
        'The AI could not answer: {reason}', 'The proposal for "{field}" changed {names}: it was not used'] as $key) {
        check(isset($es[$key]) && $es[$key] !== '' && $es[$key] !== $key, 'falta traducir: ' . $key);
    }
    foreach (['src/MailAi.php', 'src/MailController.php', 'views/admin/edit.php', 'views/admin/translate.php', 'lang/es.php'] as $file) {
        notContains('%s%%', (string) file_get_contents(__DIR__ . '/../' . $file), $file);
    }
});

test('el módulo de correo no importa clases del módulo de IA: solo pregunta por el servicio «ai»', function (): void {
    foreach (['src/MailAi.php', 'src/MailController.php'] as $file) {
        $code = (string) file_get_contents(__DIR__ . '/../' . $file);
        check(preg_match('/^use Aifec\\\\Modules\\\\Ai\\\\/m', $code) === 0, $file . ' no hace «use» del módulo de IA');
    }
});
