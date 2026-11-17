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
 * El generador de plugins: crea plugins/<id>/ con su manifiesto (con espacio de nombres propio, requisitos y una
 * declaración de contrato completa), su catálogo castellano, una prueba que pasa y su LEEME.md, y según lo que se marque:
 * un ajuste, una pantalla del panel con su tabla, un oyente, un hueco en la ficha, un bloque, una tarea periódica, una
 * orden de consola, una forma de pago de ejemplo o un transportista de ejemplo.
 *
 * Lo que sale pasa el validador del contrato.
 */
final class Generator
{
    public const FEATURES = ['setting', 'screen', 'listener', 'slot', 'block', 'job', 'command', 'payment', 'carrier'];

    /** @var Plugins */
    private $plugins;

    public function __construct(Plugins $plugins)
    {
        $this->plugins = $plugins;
    }

    /**
     * @param string[]            $features subconjunto de FEATURES
     * @param array<string,mixed> $options  description, author, license, namespace
     *
     * @return array<string,mixed> ok, message y, si se creó, id, path y files
     */
    public function create(string $id, string $name, array $features, array $options = []): array
    {
        $app = $this->plugins->app();
        $i18n = $app->i18n();
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1) {
            return $this->fail($i18n->t('The identifier may only use lowercase letters, numbers and hyphens (2 to 40 characters)'));
        }
        if ($this->plugins->isReservedId($id)) {
            return $this->fail($i18n->t('The identifier «{id}» is reserved by the system', ['id' => $id]));
        }
        $dir = $app->root() . '/plugins/' . $id;
        if (is_dir($dir) || $this->plugins->manager()->manifest($id) !== null) {
            return $this->fail($i18n->t('«{id}» already exists', ['id' => $id]));
        }
        $features = array_values(array_intersect(self::FEATURES, $features));
        $name = trim((string) preg_replace('/[\x00-\x1f"\'\\\\<>&*]+/', ' ', $name));
        $name = $name !== '' ? mb_substr($name, 0, 60) : ucwords(str_replace('-', ' ', $id));
        $description = isset($options['description']) && is_string($options['description']) && trim($options['description']) !== ''
            ? mb_substr(trim((string) preg_replace('/[\x00-\x1f"\'\\\\<>&]+/', ' ', $options['description'])), 0, 160) : 'Plugin created with the AIFEC generator';
        $author = isset($options['author']) && is_string($options['author']) && trim($options['author']) !== '' ? trim((string) preg_replace('/[\x00-\x1f*\/]+/', ' ', $options['author'])) : 'Plugin author';
        $license = isset($options['license']) && is_string($options['license']) && trim($options['license']) !== '' ? trim((string) preg_replace('/[\x00-\x1f*\/]+/', ' ', $options['license'])) : 'See LICENSE';

        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
        if (ctype_digit($studly[0])) {
            $studly = 'P' . $studly;
        }
        $namespace = isset($options['namespace']) && is_string($options['namespace']) && preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $options['namespace']) === 1 && strpos($options['namespace'], 'Aifec') !== 0
            ? $options['namespace'] : 'Plugins\\' . $studly;
        $idu = str_replace('-', '_', $id);
        if (ctype_digit($idu[0])) {
            $idu = 'p_' . $idu;
        }
        $segments = explode('-', $id);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix .= $segment[0];
        }
        if (strlen($prefix) < 2) {
            $prefix = substr(preg_replace('/[^a-z]/', '', $id) . 'xx', 0, 2);
        }
        $ctx = [
            'id' => $id, 'idu' => $idu, 'name' => $name, 'description' => $description, 'author' => $author, 'license' => $license,
            'studly' => $studly, 'namespace' => $namespace, 'table' => $idu . '_items', 'resource' => $idu . '_items', 'tab' => $prefix . 'general',
            'features' => $features,
        ];

        $files = [];
        $lang = [$name => $name, $description => $description === 'Plugin created with the AIFEC generator' ? 'Plugin creado con el generador de AIFEC' : $description];
        $requires = [];
        if (in_array('payment', $features, true)) {
            $requires[] = 'orders';
        }
        if (in_array('carrier', $features, true)) {
            $requires[] = 'shipping';
        }
        $tables = in_array('screen', $features, true) ? [$ctx['table']] : [];
        $manifest = [
            'name' => $name,
            'version' => '0.1.0',
            'description' => $description,
            'namespace' => $namespace,
            'essential' => false,
            'default' => false,
            'requires' => ['php' => '>=7.4', 'aifec' => '>=1.0.0', 'modules' => $requires],
            'contract' => ['hosts' => [], 'personal_data' => [], 'tables' => $tables, 'capabilities' => []],
        ];
        $files['plugin.json'] = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $files['hooks.php'] = $this->hooks($ctx);
        if (in_array('setting', $features, true)) {
            $files['settings.php'] = $this->settings($ctx);
            $lang['Enable this plugin'] = 'Activar este plugin';
            $lang['General'] = 'General';
        }
        if (in_array('screen', $features, true)) {
            $files['admin.php'] = $this->admin($ctx);
            $files['migrations/0001_items.php'] = $this->migration($ctx);
            $lang['See the items of this plugin'] = 'Ver los elementos de este plugin';
            $lang['Create and change the items of this plugin'] = 'Crear y cambiar los elementos de este plugin';
            $lang['Item'] = 'Elemento';
            $lang['Name'] = 'Nombre';
            $lang['Created'] = 'Creado';
            $lang['Note'] = 'Nota';
        }
        if (in_array('slot', $features, true)) {
            $files['views/front/note.php'] = $this->noteView($ctx);
            $lang['Added by this plugin'] = 'Añadido por este plugin';
        }
        if (in_array('block', $features, true)) {
            $files['blocks.php'] = $this->blocks($ctx);
        }
        if (in_array('job', $features, true)) {
            $files['jobs.php'] = $this->jobs($ctx);
            $files['src/Tasks.php'] = $this->tasks($ctx);
            $lang['Example daily task of this plugin'] = 'Tarea diaria de ejemplo de este plugin';
        }
        if (in_array('command', $features, true)) {
            $files['cli.php'] = $this->cli($ctx);
        }
        if (in_array('payment', $features, true)) {
            $files['src/ExamplePayment.php'] = $this->payment($ctx);
            $lang['Example payment'] = 'Pago de ejemplo';
            $lang['A payment method written as an example'] = 'Una forma de pago escrita como ejemplo';
            $lang['Pay with the example method'] = 'Paga con el método de ejemplo';
        }
        if (in_array('carrier', $features, true)) {
            $files['src/ExampleCarrier.php'] = $this->carrier($ctx);
        }
        $files['lang/es.php'] = $this->langFile($ctx, $lang);
        $files['tests/' . $studly . 'Test.php'] = $this->test($ctx);
        $files['LEEME.md'] = $this->readme($ctx);

        foreach ($files as $rel => $content) {
            $path = $dir . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }

        return ['ok' => true, 'message' => $i18n->t('The plugin «{id}» has been created in plugins/{id}', ['id' => $id]), 'id' => $id, 'path' => $dir, 'files' => array_keys($files)];
    }

    // ------------------------------------------------------------------ plantillas

    /**
     * @param array<string,mixed> $c
     */
    private function header(array $c, string $title): string
    {
        return "<?php\n/**\n * " . $title . "\n *\n * @author    " . $c['author'] . "\n * @license   " . $c['license'] . "\n */\n\ndeclare(strict_types=1);\n\n";
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,string> $replace
     */
    private function fill(string $template, array $c, array $replace = []): string
    {
        $map = [
            '__ID__' => $c['id'], '__IDU__' => $c['idu'], '__NS__' => $c['namespace'], '__TABLE__' => $c['table'], '__RES__' => $c['resource'],
            '__TAB__' => $c['tab'], '__NAMEQ__' => var_export($c['name'], true), '__STUDLY__' => $c['studly'],
        ];

        return strtr($template, $replace + $map);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function has(array $c, string $feature): bool
    {
        return in_array($feature, $c['features'], true);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function hooks(array $c): string
    {
        $uses = "use Aifec\\Kernel\\App;\n";
        $body = '';
        if ($this->has($c, 'payment')) {
            $uses .= 'use ' . $c['namespace'] . "\\ExamplePayment;\n";
        }
        if ($this->has($c, 'carrier')) {
            $uses .= 'use ' . $c['namespace'] . "\\ExampleCarrier;\n";
        }
        if ($this->has($c, 'listener')) {
            $guard = $this->has($c, 'setting')
                ? "        if (!\$app->settings()->bool('__IDU__.enabled', true)) {\n            return;\n        }\n"
                : '';
            $body .= "    // Reacts when an order is paid. The hook receives the order as an array.\n"
                . "    \$app->hooks()->on('order.paid', static function (array \$order) use (\$app): void {\n"
                . $guard
                . "        \$app->log()->info('__ID__ saw a paid order', ['order' => isset(\$order['id']) ? (int) \$order['id'] : 0]);\n"
                . "    });\n\n";
        }
        if ($this->has($c, 'slot')) {
            $body .= "    // Adds a piece of HTML to the product page (the theme decides where the slot goes).\n"
                . "    \$app->hooks()->slot('product.summary.after', static function (array \$product) use (\$app): string {\n"
                . "        return \$app->view()->render('__ID__::front/note', ['product' => \$product]);\n"
                . "    });\n\n";
        }
        if ($this->has($c, 'payment')) {
            $body .= "    if (\$app->has('payments')) {\n        \$app->service('payments')->register(new ExamplePayment(\$app));\n    }\n\n";
        }
        if ($this->has($c, 'carrier')) {
            $body .= "    if (\$app->has('shipping')) {\n        \$app->service('shipping')->registerHandler(new ExampleCarrier());\n    }\n\n";
        }
        if ($body === '') {
            $body = "    // Register the hooks of the plugin here, for example:\n    //   \$app->hooks()->on('order.paid', function (array \$order) { ... });\n\n";
        }

        return $this->fill($this->header($c, $c['name'] . ' — hooks, services and listeners of the plugin.') . $uses . "\nreturn static function (App \$app): void {\n" . rtrim($body) . "\n};\n", $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function settings(array $c): string
    {
        $template = <<<'PHP'
            return [
                'title' => __NAMEQ__,
                'cap' => 'settings.edit',
                'tabs' => [
                    '__TAB__' => ['label' => 'General', 'fields' => [
                        '__IDU__.enabled' => ['type' => 'toggle', 'label' => 'Enable this plugin', 'default' => true],
                    ]],
                ],
            ];

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — settings.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function admin(array $c): string
    {
        $template = <<<'PHP'
            return [
                'caps' => [
                    '__IDU__.view' => 'See the items of this plugin',
                    '__IDU__.edit' => 'Create and change the items of this plugin',
                ],
                'menu' => [
                    ['group' => 'plugins', 'items' => [
                        ['label' => __NAMEQ__, 'resource' => '__RES__', 'cap' => '__IDU__.view', 'order' => 50],
                    ]],
                ],
                'resources' => [
                    '__RES__' => [
                        'table' => '__TABLE__', 'title' => __NAMEQ__, 'singular' => 'Item', 'label_field' => 'name', 'cap' => '__IDU__',
                        'list' => [
                            'columns' => [
                                'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
                                'created_at' => ['label' => 'Created', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                            ],
                            'search' => ['name'],
                            'sort' => ['id', 'desc'],
                        ],
                        'form' => ['fields' => [
                            'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
                            'note' => ['type' => 'textarea', 'label' => 'Note'],
                        ]],
                    ],
                ],
            ];

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — panel: permissions, menu and the items screen.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function migration(array $c): string
    {
        $template = <<<'PHP'
            use Aifec\Db\Db;
            use Aifec\Db\Schema;
            use Aifec\Db\TableDef;

            return static function (Schema $schema, Db $db): void {
                $schema->create('__TABLE__', static function (TableDef $t): void {
                    $t->id();
                    $t->string('name', 120);
                    $t->text('note')->nullable();
                    $t->timestamps();
                });
            };

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — table of items.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function noteView(array $c): string
    {
        $template = <<<'PHP'
            /**
             * A note under the summary of the product.
             *
             * @var array<string,mixed> $product
             */

            ?>
            <p class="__IDU__-note"><?= e(t('Added by this plugin')) ?></p>

            PHP;

        return $this->fill("<?php\n" . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function blocks(array $c): string
    {
        $template = <<<'PHP'
            return [
                '__IDU__-note' => static function (array $attrs): string {
                    return '<p class="af-b">' . e(isset($attrs['text']) ? (string) $attrs['text'] : '') . '</p>';
                },
            ];

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — dynamic blocks.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function jobs(array $c): string
    {
        $template = <<<'PHP'
            use __NS__\Tasks;

            return [
                'handlers' => [],
                'cron' => [
                    '__IDU__.daily' => [
                        'every' => 'daily@04:10',
                        'run' => [Tasks::class, 'daily'],
                        'description' => 'Example daily task of this plugin',
                    ],
                ],
            ];

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — scheduled tasks.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function tasks(array $c): string
    {
        $template = <<<'PHP'
            namespace __NS__;

            use Aifec\Kernel\App;

            final class Tasks
            {
                /** Runs once a day. Keep it short, bounded in time and never loop over the whole history of orders. */
                public static function daily(App $app): ?string
                {
                    $app->log()->info('__ID__ daily task');

                    return null;
                }
            }

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — tasks.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function cli(array $c): string
    {
        $template = <<<'PHP'
            use Aifec\Cli\Console;
            use Aifec\Kernel\App;

            return [
                '__ID__:hello' => ['help' => 'Says hello (example command)', 'run' => static function (App $app, array $args, Console $io): int {
                    $io->line('Hello from __ID__');

                    return 0;
                }],
            ];

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — console commands.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function payment(array $c): string
    {
        $template = <<<'PHP'
            namespace __NS__;

            use Aifec\Commerce\PaymentAction;
            use Aifec\Commerce\PaymentMethod;
            use Aifec\Kernel\App;

            /** Example payment method. It is never offered (available() returns false): change it when you write the real one. */
            final class ExamplePayment implements PaymentMethod
            {
                /** @var App */
                private $app;

                public function __construct(App $app)
                {
                    $this->app = $app;
                }

                public function code(): string
                {
                    return '__IDU___example';
                }

                public function info(): array
                {
                    return ['title' => t('Example payment'), 'description' => t('A payment method written as an example'), 'icon' => ''];
                }

                public function available(array $context): bool
                {
                    return false;
                }

                public function fee(array $context): int
                {
                    return 0;
                }

                public function isTest(): bool
                {
                    return true;
                }

                public function start(array $order): PaymentAction
                {
                    return PaymentAction::instructions('<p>' . e(t('Pay with the example method')) . '</p>');
                }

                public function supportsRefund(): bool
                {
                    return false;
                }

                public function refund(array $order, int $amount, string $reason): string
                {
                    throw new \RuntimeException('This example method does not refund');
                }
            }

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — example payment method.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function carrier(array $c): string
    {
        $template = <<<'PHP'
            namespace __NS__;

            use Aifec\Commerce\ShippingHandler;

            /** Example carrier: a flat price, in cents, that can be changed in the settings of each carrier. */
            final class ExampleCarrier implements ShippingHandler
            {
                public function code(): string
                {
                    return '__IDU___flat';
                }

                public function needsAddress(): bool
                {
                    return true;
                }

                public function quote(array $carrier, array $package, array $destination): ?array
                {
                    $price = isset($carrier['settings']['price']) && is_numeric($carrier['settings']['price']) ? (int) $carrier['settings']['price'] : 500;

                    return ['price' => max(0, $price), 'min_days' => 2, 'max_days' => 4];
                }
            }

            PHP;

        return $this->fill($this->header($c, $c['name'] . ' — example carrier.') . $this->dedent($template), $c);
    }

    /**
     * @param array<string,mixed>  $c
     * @param array<string,string> $lang
     */
    private function langFile(array $c, array $lang): string
    {
        $out = $this->header($c, $c['name'] . ' — Spanish catalogue. The key is the English text of the code; do not change it.') . "return [\n";
        foreach ($lang as $english => $spanish) {
            $out .= '    ' . var_export($english, true) . ' => ' . var_export($spanish, true) . ",\n";
        }

        return $out . "];\n";
    }

    /**
     * @param array<string,mixed> $c
     */
    private function test(array $c): string
    {
        $checks = '';
        if ($this->has($c, 'setting')) {
            $checks .= "    same(true, \$app->settings()->bool('__IDU__.enabled'));\n";
        }
        if ($this->has($c, 'screen')) {
            $checks .= "    check(\$app->db()->tableExists('__TABLE__'), 'the table of items exists');\n";
        }
        if ($this->has($c, 'listener')) {
            $checks .= "    check(in_array('__ID__', \$app->hooks()->modulesOn('order.paid'), true), 'it listens to order.paid');\n";
        }
        if ($this->has($c, 'slot')) {
            $checks .= "    check(in_array('__ID__', \$app->hooks()->modulesOn('product.summary.after'), true), 'it fills the product slot');\n";
        }
        if ($this->has($c, 'block')) {
            $checks .= "    check(isset(\$app->modules()->definition('__ID__', 'blocks')['__IDU__-note']), 'it declares its block');\n";
        }
        if ($this->has($c, 'job')) {
            $checks .= "    check(isset(\$app->modules()->definition('__ID__', 'jobs')['cron']['__IDU__.daily']), 'it declares its task');\n";
        }
        if ($this->has($c, 'command')) {
            $checks .= "    check(isset(\$app->modules()->definition('__ID__', 'cli')['__ID__:hello']), 'it declares its command');\n";
        }
        if ($this->has($c, 'payment')) {
            $checks .= "    check(class_exists('__NS__\\\\ExamplePayment'), 'the example payment method exists');\n";
        }
        if ($this->has($c, 'carrier')) {
            $checks .= "    check(class_exists('__NS__\\\\ExampleCarrier'), 'the example carrier exists');\n";
        }
        if ($checks === '') {
            $checks = "    check(true, 'this plugin has no extra pieces yet');\n";
        }
        $template = <<<'PHP'
            use Aifec\Kernel\App;

            /** Sitio de pruebas con el plugin activo y el gestor de plugins cargado. */
            function __IDU__Site(): App
            {
                $app = testApp(['modules' => ['plugins', '__ID__'], 'settings' => ['modules.active' => ['plugins', '__ID__']]]);
                $app->migrate(true);
                $app->boot();

                return $app;
            }

            test('__ID__ se activa y se carga', function (): void {
                $app = __IDU__Site();
                check($app->modules()->isLoaded('__ID__'), 'the plugin is loaded');
                check(!isset($app->modules()->failed()['__ID__']), 'the plugin did not fail to load');
            });

            test('__ID__ hace lo suyo', function (): void {
                $app = __IDU__Site();
            __CHECKS__});

            test('__ID__ se desactiva', function (): void {
                $app = __IDU__Site();
                $r = $app->service('plugins')->disable('__ID__');
                same(true, $r['ok'], (string) $r['message']);
                check(!in_array('__ID__', $app->settings()->arr('modules.active'), true), 'it is no longer in modules.active');
            });

            test('__ID__ pasa el contrato y se desinstala sin dejar nada', function (): void {
                $app = __IDU__Site();
                $plugins = $app->service('plugins');
                $plugins->useTempBase(tmpDir());
                $report = $plugins->validate('__ID__');
                $errors = [];
                foreach ($report['checks'] as $check) {
                    if (!$check['ok'] && $check['level'] === 'error') {
                        $errors[] = $check['rule'] . ': ' . $check['message'];
                    }
                }
                same([], $errors);
                same(true, $report['ok']);
                same(true, $report['dynamic']);
            });

            PHP;
        $body = str_replace('__CHECKS__', $checks, $this->dedent($template));

        return $this->fill($this->header($c, $c['name'] . ' — tests: it activates, does its job, turns off and uninstalls.') . $body, $c);
    }

    /**
     * @param array<string,mixed> $c
     */
    private function readme(array $c): string
    {
        $pieces = [
            'setting' => 'a setting (`settings.php`)', 'screen' => 'a panel screen with its table (`admin.php`, `migrations/`)',
            'listener' => 'a listener of `order.paid` (`hooks.php`)', 'slot' => 'a slot in the product page (`hooks.php`, `views/front/note.php`)',
            'block' => 'a dynamic block (`blocks.php`)', 'job' => 'a daily task (`jobs.php`, `src/Tasks.php`)',
            'command' => 'a console command (`cli.php`)', 'payment' => 'an example payment method (`src/ExamplePayment.php`)',
            'carrier' => 'an example carrier (`src/ExampleCarrier.php`)',
        ];
        $list = '';
        foreach ($c['features'] as $feature) {
            $list .= '- ' . $pieces[$feature] . "\n";
        }
        if ($list === '') {
            $list = "- nada todavía: es un esqueleto vacío\n";
        }
        $listEs = [
            'a setting (`settings.php`)' => 'un ajuste (`settings.php`)',
            'a panel screen with its table (`admin.php`, `migrations/`)' => 'una pantalla del panel con su tabla (`admin.php`, `migrations/`)',
            'a listener of `order.paid` (`hooks.php`)' => 'un oyente de `order.paid` (`hooks.php`)',
            'a slot in the product page (`hooks.php`, `views/front/note.php`)' => 'un hueco en la ficha de producto (`hooks.php`, `views/front/note.php`)',
            'a dynamic block (`blocks.php`)' => 'un bloque dinámico (`blocks.php`)',
            'a daily task (`jobs.php`, `src/Tasks.php`)' => 'una tarea diaria (`jobs.php`, `src/Tasks.php`)',
            'a console command (`cli.php`)' => 'una orden de consola (`cli.php`)',
            'an example payment method (`src/ExamplePayment.php`)' => 'una forma de pago de ejemplo (`src/ExamplePayment.php`)',
            'an example carrier (`src/ExampleCarrier.php`)' => 'un transportista de ejemplo (`src/ExampleCarrier.php`)',
        ];
        $list = strtr($list, $listEs);

        return '# ' . $c['name'] . "\n\n" . $c['description'] . "\n\nPlugin de AIFEC creado con el generador (`php aifec plugin:create`).\n\n## Qué lleva\n\n" . $list
            . "\n## Cómo se usa\n\n1. Entra en el panel, en «Módulos y plugins», y pulsa «Activar». Antes de activarse pasa el validador del Contrato AIFEC.\n"
            . '2. Para comprobarlo por consola: `php aifec contract:check ' . $c['id'] . "`.\n"
            . '3. Para probarlo: `php tests/run.php plugins/' . $c['id'] . "`.\n"
            . '4. Para comprobar las traducciones: `php aifec i18n:check plugins/' . $c['id'] . "`.\n\n"
            . "## Reglas que conviene recordar\n\n- Declara en `plugin.json` los dominios a los que llama, los datos personales que usa, las tablas que crea y las capacidades que pide.\n"
            . "- Todo texto que ve una persona va en inglés dentro de `t('…')` y su traducción en `lang/es.php`.\n"
            . "- Nada de `echo`, `header()` ni `exit` fuera de `views/`; en las vistas, todo dato pasa por `e()`.\n"
            . "- El dinero se toca solo por los cauces del sistema; sin la capacidad `pricing` no se puede enganchar un filtro de precios.\n"
            . "- Las llamadas a servicios externos van por `\$app->http()` y a dominios declarados; el correo, por el servicio de correo.\n";
    }

    /** Quita la sangría común de una plantilla escrita dentro de un método. */
    private function dedent(string $text): string
    {
        $lines = explode("\n", $text);
        $min = 1000;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $min = min($min, strlen($line) - strlen(ltrim($line)));
        }
        $min = $min === 1000 ? 0 : $min;
        foreach ($lines as $i => $line) {
            $lines[$i] = strlen($line) >= $min ? substr($line, $min) : ltrim($line);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string,mixed>
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
