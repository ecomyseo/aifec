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

use Aifec\Cli\I18nCheck;
use Aifec\Kernel\I18n;

/**
 * Las reglas del Contrato AIFEC que se comprueban sin ejecutar nada: leen el código de un plugin por fichas de PHP
 * (token_get_all), así que una palabra prohibida dentro de un comentario o de una cadena de ayuda no salta.
 *
 * Reglas: M1 (manifiesto), P1 (código prohibido), C1 (dinero), C2 (datos del producto), C3 (peso y recursos),
 * C4 (salida), C5 (red y datos personales), C6 (cauces comunes), C8 (traducciones) y C9 (tablas que crea).
 *
 * Cada hallazgo lleva la regla, el nivel (error o warning), el mensaje ya traducido, el fichero y la línea.
 */
final class StaticChecks
{
    private const PROHIBITED_CALLS = ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'create_function', 'pcntl_exec'];
    private const WRITE_CALLS = ['file_put_contents', 'unlink', 'rmdir', 'mkdir', 'rename', 'copy', 'touch', 'chmod', 'symlink', 'move_uploaded_file'];
    private const OUTPUT_CALLS = ['header', 'header_remove', 'setcookie', 'http_response_code', 'printf', 'vprintf', 'var_dump', 'readfile', 'fpassthru'];
    private const NETWORK_CALLS = ['fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'get_headers'];
    private const URL_READERS = ['file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'simplexml_load_file'];
    private const SAFE_FUNCTIONS = ['e', 't', 'tn', 'url', 'route', 'asset', 'money', 'attrs', 'json_html', 'number', 'count', 'intval', 'hook', 'htmlspecialchars'];
    private const SAFE_ADMIN = ['tokenField', 'icon', 'badge', 'helpText'];
    private const SAFE_VIEW_METHODS = ['partial', 'section', 'stack', 'layout', 'start', 'stop', 'push'];
    private const IGNORED_HOSTS = ['www.w3.org', 'w3.org', 'schema.org', 'www.schema.org', 'json-schema.org', 'purl.org', 'ns.adobe.com', 'xmlns.com'];
    private const AI_HOSTS = [
        'generativelanguage.googleapis.com', 'aiplatform.googleapis.com', 'api.openai.com', 'api.anthropic.com', 'openrouter.ai',
        'api.mistral.ai', 'api.cohere.ai', 'api.cohere.com', 'api.groq.com', 'api.deepseek.com', 'api.x.ai', 'api.perplexity.ai',
        'api-inference.huggingface.co', 'api.together.xyz', 'api.replicate.com',
    ];
    private const PERSONAL_TABLES = ['customers', 'addresses', 'orders', 'order_items', 'order_history', 'order_payments'];
    private const KNOWN_CAPABILITIES = ['pricing', 'orders'];

    /** Peso de los recursos: por encima del primer tope, aviso; del segundo, error. */
    private const ASSETS_WARN = 61440;
    private const ASSETS_ERROR = 204800;

    /** @var I18n */
    private $i18n;

    /** @var string raíz que contiene plugins/<id> */
    private $root;

    /** @var string */
    private $id;

    /** @var string */
    private $dir;

    /** @var array<string,mixed> */
    private $manifest;

    /** @var array<string,bool> tablas que no son de nadie más que del sistema o de otros módulos */
    private $protected;

    /** @var array<string,string> gancho sellado => capacidad */
    private $sealed;

    /** @var string[] */
    private $ownTables = [];

    /** @var string[] */
    private $hosts = [];

    /** @var string[] */
    private $capabilities = [];

    /** @var array<int,array{rule:string,level:string,message:string,file:string,line:int}> */
    private $findings = [];

    /** @var array<string,mixed> */
    private $measured = [];

    /** @var array<string,bool> */
    private $seen = [];

    /**
     * @param array<string,mixed>  $manifest   el plugin.json ya leído (vacío si no es válido)
     * @param array<string,bool>   $protected   tablas del sistema y de otros módulos (nombre => true)
     * @param array<string,string> $sealed      ganchos sellados y la capacidad que exigen
     */
    public function __construct(I18n $i18n, string $root, string $id, array $manifest, array $protected, array $sealed)
    {
        $this->i18n = $i18n;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->id = $id;
        $this->dir = $this->root . '/plugins/' . $id;
        $this->manifest = $manifest;
        $this->protected = $protected;
        $this->sealed = $sealed;
        $contract = isset($manifest['contract']) && is_array($manifest['contract']) ? $manifest['contract'] : [];
        $this->ownTables = $this->strings($contract, 'tables');
        $this->hosts = array_map('strtolower', $this->strings($contract, 'hosts'));
        $this->capabilities = $this->strings($contract, 'capabilities');
    }

    /**
     * @return array{findings:array<int,array{rule:string,level:string,message:string,file:string,line:int}>,measured:array<string,mixed>}
     */
    public function run(): array
    {
        $files = Fs::files($this->dir);
        $phpFiles = 0;
        $assetBytes = 0;
        $assetFiles = 0;
        $this->checkManifest($files);
        foreach ($files as $rel) {
            if (strpos($rel, 'tests/') === 0) {
                continue;
            }
            if (substr($rel, -4) === '.php') {
                ++$phpFiles;
                $this->scanFile($rel);
            }
            if (strpos($rel, 'assets/') === 0) {
                ++$assetFiles;
                $assetBytes += (int) filesize($this->dir . '/' . $rel);
                if (substr($rel, -4) === '.css') {
                    $this->scanCss($rel);
                }
            }
        }
        $this->checkAssetWeight($assetBytes);
        $this->checkTranslations();
        $this->measured['files'] = count($files);
        $this->measured['php_files'] = $phpFiles;
        $this->measured['assets_files'] = $assetFiles;
        $this->measured['assets_bytes'] = $assetBytes;
        $this->measured['tables_declared'] = count($this->ownTables);
        $this->measured['hosts_declared'] = count($this->hosts);
        foreach (['hosts_found', 'sealed_hooks'] as $key) {
            if (isset($this->measured[$key])) {
                $this->measured[$key] = array_keys($this->measured[$key]);
            }
        }

        return ['findings' => $this->findings, 'measured' => $this->measured];
    }

    // ------------------------------------------------------------------ manifiesto

    /**
     * @param string[] $files
     */
    private function checkManifest(array $files): void
    {
        $file = 'plugin.json';
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $this->id) !== 1) {
            $this->add('M1', 'error', $this->i18n->t('The identifier may only use lowercase letters, numbers and hyphens'), $file, 0);
        }
        if ($this->manifest === []) {
            $this->add('M1', 'error', $this->i18n->t('plugin.json is missing or is not valid JSON'), $file, 0);

            return;
        }
        $m = $this->manifest;
        if (!isset($m['name']) || !is_string($m['name']) || trim($m['name']) === '') {
            $this->add('M1', 'error', $this->i18n->t('plugin.json needs a name'), $file, 0);
        }
        if (!isset($m['version']) || !is_string($m['version']) || preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', $m['version']) !== 1) {
            $this->add('M1', 'error', $this->i18n->t('plugin.json needs a version like 1.0.0'), $file, 0);
        }
        $hasSrc = false;
        foreach ($files as $rel) {
            $hasSrc = $hasSrc || strpos($rel, 'src/') === 0;
        }
        if (isset($m['namespace'])) {
            if (!is_string($m['namespace']) || preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $m['namespace']) !== 1 || strpos($m['namespace'], 'Aifec\\') === 0 || $m['namespace'] === 'Aifec') {
                $this->add('M1', 'error', $this->i18n->t('The namespace is not valid or uses the system one'), $file, 0);
            }
        } elseif ($hasSrc) {
            $this->add('M1', 'error', $this->i18n->t('A plugin with a src folder must declare its own namespace'), $file, 0);
        }
        $contract = isset($m['contract']) && is_array($m['contract']) ? $m['contract'] : null;
        if ($contract === null) {
            $this->add('M1', 'error', $this->i18n->t('The contract declaration is missing'), $file, 0);

            return;
        }
        foreach (['hosts', 'personal_data', 'tables', 'capabilities'] as $key) {
            if (!isset($contract[$key]) || !is_array($contract[$key])) {
                $this->add('M1', 'error', $this->i18n->t('The contract must declare «{key}» (an empty list if it uses none)', ['key' => $key]), $file, 0);
            }
        }
        foreach ($this->ownTables as $table) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                $this->add('M1', 'error', $this->i18n->t('The declared table «{table}» has an invalid name', ['table' => $table]), $file, 0);
            } elseif (isset($this->protected[$table])) {
                $this->add('M1', 'error', $this->i18n->t('The declared table «{table}» belongs to the system or to another module', ['table' => $table]), $file, 0);
            }
        }
        foreach ($this->capabilities as $capability) {
            if (!in_array($capability, self::KNOWN_CAPABILITIES, true)) {
                $this->add('M1', 'warning', $this->i18n->t('The capability «{capability}» is not known to the system', ['capability' => $capability]), $file, 0);
            }
        }
    }

    // ------------------------------------------------------------------ un fichero de PHP

    private function scanFile(string $rel): void
    {
        $source = (string) file_get_contents($this->dir . '/' . $rel);
        $tokens = $this->tokens($source);
        $isView = strpos($rel, 'views/') === 0;
        $this->prohibited($rel, $tokens);
        $this->money($rel, $tokens);
        $this->productExtra($rel, $tokens);
        $this->output($rel, $tokens, $isView);
        $this->network($rel, $tokens, $isView);
        $this->commonChannels($rel, $tokens, $source);
        if ($isView) {
            $this->externalResources($rel, $source);
        }
        if (strpos($rel, 'migrations/') === 0) {
            $this->migrationTables($rel, $tokens);
        }
    }

    /** P1: lo que un plugin no puede escribir nunca. */
    private function prohibited(string $rel, array $t): void
    {
        $n = count($t);
        foreach ($t as $i => $tok) {
            if ($tok[0] === T_EVAL) {
                $this->add('P1', 'error', $this->i18n->t('Uses eval()'), $rel, $tok[2]);
            } elseif ($tok[1] === '`' && $tok[0] === null) {
                $this->add('P1', 'error', $this->i18n->t('Runs a shell command with backticks'), $rel, $tok[2]);
            } elseif ($this->isCall($t, $i)) {
                $name = strtolower($tok[1]);
                if (in_array($name, self::PROHIBITED_CALLS, true)) {
                    $this->add('P1', 'error', $this->i18n->t('Calls the forbidden function {name}()', ['name' => $name]), $rel, $tok[2]);
                } elseif ($name === 'assert' && isset($t[$i + 2]) && $t[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $this->add('P1', 'error', $this->i18n->t('Uses assert() with a string, which runs code'), $rel, $tok[2]);
                } elseif (in_array($name, self::WRITE_CALLS, true) || $name === 'fopen') {
                    $call = $this->args($t, $i + 1);
                    if ($call['args'] === []) {
                        continue;
                    }
                    if ($name === 'fopen') {
                        $mode = isset($call['args'][1]) ? $this->literal($t, $call['args'][1]) : null;
                        if ($mode === null || preg_match('/[waxc+]/', $mode) !== 1) {
                            continue;
                        }
                    }
                    $targets = in_array($name, ['copy', 'symlink'], true) ? [1] : ($name === 'rename' ? [0, 1] : [0]);
                    foreach ($targets as $target) {
                        if (isset($call['args'][$target]) && $this->looksOutside($t, $call['args'][$target])) {
                            $this->add('P1', 'error', $this->i18n->t('Writes outside the data folder of the site with {name}()', ['name' => $name]), $rel, $tok[2]);
                            break;
                        }
                    }
                }
            }
        }
        foreach ($this->statements($t) as $range) {
            $hasDecode = false;
            $hasRun = false;
            $hasInclude = false;
            $hasRequestVar = false;
            $line = 0;
            for ($j = $range[0]; $j < $range[1]; $j++) {
                $x = $t[$j];
                if ($x[0] === T_STRING && strtolower($x[1]) === 'base64_decode' && $this->isCall($t, $j)) {
                    $hasDecode = true;
                }
                if (in_array($x[0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    $hasRun = true;
                    $line = $x[2];
                }
                if (in_array($x[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    $hasInclude = true;
                }
                if ($x[0] === T_VARIABLE && (in_array($x[1], ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER', '$request', '$req'], true))) {
                    $hasRequestVar = true;
                }
            }
            if ($hasDecode && $hasRun) {
                $this->add('P1', 'error', $this->i18n->t('Decodes base64 and runs the result'), $rel, $line);
            }
            if ($hasInclude && $hasRequestVar) {
                $this->add('P1', 'error', $this->i18n->t('Includes a file whose path comes from the request'), $rel, $line);
            }
        }
    }

    /** C1: dinero. Escribir en tablas del comercio que no son suyas y enganchar un filtro sellado sin la capacidad. */
    private function money(string $rel, array $t): void
    {
        $pattern = '/\b(INSERT\s+(?:OR\s+\w+\s+)?(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM|UPDATE(?=\s+[`{"\[]?[a-z_]))\s*[`{"\[]*([a-z][a-z0-9_]*)/i';
        foreach ($this->pieces($t) as $piece) {
            if (preg_match_all($pattern, $piece[0], $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $m) {
                if (strtoupper(substr($m[1], 0, 6)) === 'UPDATE' && preg_match('/\bSET\b/i', $piece[0]) !== 1) {
                    continue;
                }
                $table = strtolower($m[2]);
                if ($this->isForeignTable($table)) {
                    $this->add('C1', 'error', $this->i18n->t('Writes to the table «{table}», which is not its own', ['table' => $this->bare($table)]), $rel, $piece[1]);
                }
            }
        }
        $n = count($t);
        for ($i = 0; $i < $n - 3; $i++) {
            if ($t[$i][1] !== '->' || $t[$i + 1][0] !== T_STRING || $t[$i + 2][1] !== '(' || $t[$i + 3][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $method = strtolower($t[$i + 1][1]);
            $value = $this->decode($t[$i + 3][1]);
            if (in_array($method, ['insert', 'update', 'delete', 'upsert', 'insertignore'], true) && $this->isForeignTable($value)) {
                $this->add('C1', 'error', $this->i18n->t('Writes to the table «{table}», which is not its own', ['table' => $this->bare($value)]), $rel, $t[$i + 1][2]);
            }
            if (in_array($method, ['filter', 'on', 'slot'], true) && isset($this->sealed[$value]) && !in_array($this->sealed[$value], $this->capabilities, true)) {
                $this->add('C1', 'error', $this->i18n->t('Hooks into the sealed hook «{hook}» without declaring the capability «{capability}»', ['hook' => $value, 'capability' => $this->sealed[$value]]), $rel, $t[$i + 1][2]);
            }
            if (isset($this->sealed[$value])) {
                $this->measured['sealed_hooks'][$value] = true;
            }
        }
    }

    /** C2: lo que se añade al producto se declara con su etiqueta, su tipo y si es público. */
    private function productExtra(string $rel, array $t): void
    {
        $n = count($t);
        for ($i = 0; $i < $n - 3; $i++) {
            if ($t[$i][1] !== '->' || $t[$i + 1][0] !== T_STRING || strtolower($t[$i + 1][1]) !== 'filter' || $t[$i + 2][1] !== '(' || $t[$i + 3][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($this->decode($t[$i + 3][1]) !== 'product.extra') {
                continue;
            }
            $call = $this->args($t, $i + 2);
            $inline = isset($call['args'][1]) && $call['args'][1][1] > $call['args'][1][0] && in_array($t[$call['args'][1][0]][0], [T_FUNCTION, T_FN, T_STATIC], true);
            $from = $inline ? $call['args'][1][0] : 0;
            $to = $inline ? $call['args'][1][1] : $n;
            $keys = [];
            for ($j = $from; $j < $to - 1; $j++) {
                if ($t[$j][0] === T_CONSTANT_ENCAPSED_STRING && $t[$j + 1][0] === T_DOUBLE_ARROW) {
                    $keys[$this->decode($t[$j][1])] = true;
                }
            }
            $missing = [];
            foreach (['label', 'type', 'public'] as $need) {
                if (!isset($keys[$need])) {
                    $missing[] = $need;
                }
            }
            if ($missing !== []) {
                $this->add('C2', 'error', $this->i18n->t('The data added to the product must declare label, type and public (missing: {missing})', ['missing' => implode(', ', $missing)]), $rel, $t[$i + 1][2]);
            }
        }
    }

    /** C4: salida. Nada de echo, print, header ni exit fuera de las vistas, y en las vistas, todo escapado. */
    private function output(string $rel, array $t, bool $isView): void
    {
        $n = count($t);
        foreach ($t as $i => $tok) {
            if ($tok[0] === T_EXIT) {
                $this->add('C4', 'error', $this->i18n->t('Ends the request with exit or die'), $rel, $tok[2]);
            } elseif ($tok[0] === T_STRING && $this->isCall($t, $i) && in_array(strtolower($tok[1]), ['header', 'header_remove', 'setcookie', 'http_response_code'], true)) {
                $this->add('C4', 'error', $this->i18n->t('Sends headers with {name}()', ['name' => strtolower($tok[1])]), $rel, $tok[2]);
            }
            if ($isView) {
                continue;
            }
            if (in_array($tok[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                $this->add('C4', 'error', $this->i18n->t('Writes output directly outside the views'), $rel, $tok[2]);
            } elseif ($tok[0] === T_INLINE_HTML && trim($tok[1]) !== '') {
                $this->add('C4', 'error', $this->i18n->t('Has HTML outside the views'), $rel, $tok[2]);
            } elseif ($tok[0] === T_STRING && $this->isCall($t, $i)) {
                $name = strtolower($tok[1]);
                if (in_array($name, ['printf', 'vprintf', 'var_dump', 'readfile', 'fpassthru'], true)) {
                    $this->add('C4', 'error', $this->i18n->t('Writes output directly with {name}()', ['name' => $name]), $rel, $tok[2]);
                }
            }
        }
        if (!$isView) {
            return;
        }
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($t[$i][0], [T_OPEN_TAG_WITH_ECHO, T_ECHO], true)) {
                continue;
            }
            $end = $i + 1;
            while ($end < $n && $t[$end][1] !== ';' && $t[$end][0] !== T_CLOSE_TAG) {
                ++$end;
            }
            if ($this->unsafeExpression($t, $i + 1, $end)) {
                $this->add('C4', 'warning', $this->i18n->t('Prints a value that does not go through e() or another safe function'), $rel, $t[$i][2]);
            }
            $i = $end;
        }
    }

    /** C5: red y datos personales. */
    private function network(string $rel, array $t, bool $isView): void
    {
        $personal = false;
        foreach ($t as $i => $tok) {
            if ($tok[0] !== T_STRING || !$this->isCall($t, $i)) {
                continue;
            }
            $name = strtolower($tok[1]);
            if (strpos($name, 'curl_') === 0 || in_array($name, self::NETWORK_CALLS, true)) {
                $this->add('C5', 'error', $this->i18n->t('Opens connections on its own with {name}(); it has to use $app->http()', ['name' => $name]), $rel, $tok[2]);
            } elseif (in_array($name, self::URL_READERS, true)) {
                $call = $this->args($t, $i + 1);
                $url = isset($call['args'][0]) ? $this->literal($t, $call['args'][0]) : null;
                if ($url !== null && preg_match('~^(?:https?|ftp)://|^//~i', $url) === 1) {
                    $this->add('C5', 'error', $this->i18n->t('Reads a web address with {name}(); it has to use $app->http()', ['name' => $name]), $rel, $tok[2]);
                }
            } elseif ($name === 'service') {
                $call = $this->args($t, $i + 1);
                $service = isset($call['args'][0]) ? $this->literal($t, $call['args'][0]) : null;
                if ($service === 'customers' || $service === 'orders') {
                    $personal = $personal ? $personal : $tok[2];
                }
            }
        }
        $hostSeen = [];
        foreach ($this->pieces($t) as $piece) {
            if (preg_match_all('~\b(?:https?|wss?|ftp)://([a-z0-9][a-z0-9.-]*[a-z0-9])~i', $piece[0], $matches) > 0) {
                foreach ($matches[1] as $host) {
                    $host = strtolower($host);
                    if (in_array($host, self::IGNORED_HOSTS, true) || strpos($host, '.') === false || isset($hostSeen[$host])) {
                        continue;
                    }
                    $hostSeen[$host] = true;
                    $this->measured['hosts_found'][$host] = true;
                    if (!$this->hostDeclared($host)) {
                        $this->add('C5', 'error', $this->i18n->t('Uses the domain «{host}», which is not declared in contract.hosts', ['host' => $host]), $rel, $piece[1]);
                    }
                }
            }
            if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+[`{"\[]*(?:af_)?(' . implode('|', self::PERSONAL_TABLES) . ')\b/i', $piece[0]) === 1 && $personal === false) {
                $personal = $piece[1];
            }
        }
        for ($i = 0; $i < count($t) - 3; $i++) {
            if ($t[$i][1] === '->' && $t[$i + 1][0] === T_STRING && in_array(strtolower($t[$i + 1][1]), ['find', 'insert', 'update', 'delete', 'upsert'], true)
                && $t[$i + 2][1] === '(' && $t[$i + 3][0] === T_CONSTANT_ENCAPSED_STRING && in_array($this->bare($this->decode($t[$i + 3][1])), ['customers', 'addresses', 'orders'], true)) {
                $personal = $personal === false ? $t[$i + 1][2] : $personal;
            }
        }
        $declared = isset($this->manifest['contract']['personal_data']) && is_array($this->manifest['contract']['personal_data']) ? $this->manifest['contract']['personal_data'] : [];
        if ($personal !== false && $declared === []) {
            $this->add('C5', 'error', $this->i18n->t('Reads customers, addresses or orders but contract.personal_data is empty'), $rel, (int) $personal);
        }
    }

    /** C6: IA, correo y tareas por los cauces comunes. */
    private function commonChannels(string $rel, array $t, string $source): void
    {
        foreach ($t as $i => $tok) {
            if ($tok[0] === T_STRING && $this->isCall($t, $i)) {
                $name = strtolower($tok[1]);
                if ($name === 'mail' || $name === 'mb_send_mail') {
                    $this->add('C6', 'error', $this->i18n->t('Sends mail with {name}(); it has to use the mail service', ['name' => $name]), $rel, $tok[2]);
                } elseif ($name === 'sleep' || $name === 'usleep') {
                    $this->add('C6', 'error', $this->i18n->t('Stops the request with {name}()', ['name' => $name]), $rel, $tok[2]);
                } elseif ($name === 'set_time_limit') {
                    $call = $this->args($t, $i + 1);
                    if (isset($call['args'][0]) && $this->literal($t, $call['args'][0]) === '0') {
                        $this->add('C6', 'error', $this->i18n->t('Removes the time limit with set_time_limit(0)'), $rel, $tok[2]);
                    }
                }
            }
            if ($tok[0] === T_STRING && $i > 0 && in_array($t[$i - 1][0], [T_NEW, T_EXTENDS], true) && preg_match('/(PHPMailer|Swift_Mailer|SmtpTransport|SmtpConnection)/', $tok[1]) === 1) {
                $this->add('C6', 'error', $this->i18n->t('Uses its own mail transport; it has to use the mail service'), $rel, $tok[2]);
            }
        }
        foreach ($this->pieces($t) as $piece) {
            foreach (self::AI_HOSTS as $host) {
                if (stripos($piece[0], $host) !== false) {
                    $this->add('C6', 'error', $this->i18n->t('Calls the AI service of «{host}» directly; it has to use the ai service', ['host' => $host]), $rel, $piece[1]);
                }
            }
        }
        $jobFile = basename($rel) === 'jobs.php' || preg_match('~(^|/)(Jobs?|Tasks?)[A-Za-z]*\.php$~', $rel) === 1 || strpos($source, "'cron'") !== false || strpos($source, "'handlers'") !== false;
        if (!$jobFile) {
            return;
        }
        foreach ($this->pieces($t) as $piece) {
            if (preg_match('/\bSELECT\b[\s\S]*?\bFROM\s+[`{"\[]*(?:af_)?(orders|customers)\b/i', $piece[0]) === 1
                && preg_match('/\b(created_at|updated_at|placed_at|paid_at|id|order_id|customer_id)\b\s*(=|<=|>=|<|>|IN\b|BETWEEN\b)/i', $piece[0]) !== 1) {
                $this->add('C6', 'warning', $this->i18n->t('A task reads the whole history of orders or customers without a date or id limit'), $rel, $piece[1]);
            }
        }
    }

    /** C3: recursos externos que bloquean la página. */
    private function externalResources(string $rel, string $source): void
    {
        $patterns = [
            '/<script\b[^>]*\bsrc\s*=\s*["\']?(?:https?:)?\/\/[^"\'>\s]+/i',
            '/<link\b[^>]*\bhref\s*=\s*["\']?(?:https?:)?\/\/[^"\'>\s]+/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as $m) {
                    $this->add('C3', 'error', $this->i18n->t('Loads a script or style from another domain'), $rel, substr_count(substr($source, 0, (int) $m[1]), "\n") + 1);
                }
            }
        }
    }

    private function scanCss(string $rel): void
    {
        $css = (string) file_get_contents($this->dir . '/' . $rel);
        if (preg_match_all('/@import\b|url\(\s*[\'"]?(?:https?:)?\/\//i', $css, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $m) {
                $this->add('C3', 'error', $this->i18n->t('The stylesheet imports a file or loads a resource from another domain'), $rel, substr_count(substr($css, 0, (int) $m[1]), "\n") + 1);
            }
        }
    }

    private function checkAssetWeight(int $bytes): void
    {
        $kb = (int) round($bytes / 1024);
        if ($bytes > self::ASSETS_ERROR) {
            $this->add('C3', 'error', $this->i18n->t('The assets weigh {kb} KB; the limit is 200 KB', ['kb' => $kb]), 'assets', 0);
        } elseif ($bytes > self::ASSETS_WARN) {
            $this->add('C3', 'warning', $this->i18n->t('The assets weigh {kb} KB; above 60 KB is heavy', ['kb' => $kb]), 'assets', 0);
        }
    }

    /**
     * C9 estático: las tablas que crea con sus migraciones tienen que estar declaradas.
     */
    private function migrationTables(string $rel, array $t): void
    {
        $tables = [];
        for ($i = 0; $i < count($t) - 3; $i++) {
            if ($t[$i][1] === '->' && $t[$i + 1][0] === T_STRING && strtolower($t[$i + 1][1]) === 'create' && $t[$i + 2][1] === '(' && $t[$i + 3][0] === T_CONSTANT_ENCAPSED_STRING) {
                $tables[] = [$this->decode($t[$i + 3][1]), $t[$i + 1][2]];
            }
        }
        foreach ($this->pieces($t) as $piece) {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`{"\[]*([a-z][a-z0-9_]*)/i', $piece[0], $m) === 1) {
                $tables[] = [strtolower($m[1]), $piece[1]];
            }
        }
        foreach ($tables as $table) {
            $name = $this->bare($table[0]);
            if (!in_array($name, $this->ownTables, true) && !in_array($table[0], $this->ownTables, true)) {
                $this->add('C9', 'error', $this->i18n->t('Creates the table «{table}» without declaring it in contract.tables', ['table' => $name]), $rel, $table[1]);
            }
        }
    }

    /** C8: todas sus cadenas, en su lang/es.php. */
    private function checkTranslations(): void
    {
        try {
            $result = I18nCheck::run($this->root, 'plugins/' . $this->id, 'es');
        } catch (\InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'plain list') !== false) {
                $this->add('C8', 'error', $this->i18n->t('lang/es.php must be a plain list of texts: no code'), 'lang/es.php', 0);
            }

            return;
        }
        $this->measured['strings'] = $result['strings'];
        $shown = 0;
        foreach ($result['missing'] as $string => $where) {
            if (++$shown > 40) {
                $this->add('C8', 'error', $this->i18n->t('And {count} more texts without translation', ['count' => count($result['missing']) - 40]), 'lang/es.php', 0);
                break;
            }
            $short = mb_strlen((string) $string) > 80 ? mb_substr((string) $string, 0, 80) . '…' : (string) $string;
            $this->add('C8', 'error', $this->i18n->t('Text without Spanish translation: «{text}»', ['text' => $short]), (string) preg_replace('~^plugins/' . preg_quote($this->id, '~') . '/~', '', (string) $where), 0);
        }
    }

    // ------------------------------------------------------------------ ayudas

    private function add(string $rule, string $level, string $message, string $file, int $line): void
    {
        $key = $rule . '|' . $level . '|' . $message . '|' . $file . '|' . $line;
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->findings[] = ['rule' => $rule, 'level' => $level, 'message' => $message, 'file' => $file, 'line' => $line];
    }

    /**
     * @param array<string,mixed> $contract
     *
     * @return string[]
     */
    private function strings(array $contract, string $key): array
    {
        $out = [];
        foreach (isset($contract[$key]) && is_array($contract[$key]) ? $contract[$key] : [] as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function hostDeclared(string $host): bool
    {
        foreach ($this->hosts as $declared) {
            if ($host === $declared || substr($host, -strlen($declared) - 1) === '.' . $declared) {
                return true;
            }
        }

        return false;
    }

    /** Quita el prefijo de tablas («af_products» → «products»). */
    private function bare(string $table): string
    {
        $table = strtolower($table);

        return strpos($table, 'af_') === 0 ? substr($table, 3) : $table;
    }

    private function isForeignTable(string $table): bool
    {
        foreach ([strtolower($table), $this->bare($table)] as $name) {
            if (isset($this->protected[$name]) && !in_array($name, $this->ownTables, true)) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }

    /**
     * El valor literal de un argumento si es una sola cadena o un número; si no, null.
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     * @param array{0:int,1:int}                      $range
     */
    private function literal(array $t, array $range): ?string
    {
        if ($range[1] - $range[0] !== 1) {
            return null;
        }
        $tok = $t[$range[0]];
        if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            return $this->decode($tok[1]);
        }
        if ($tok[0] === T_LNUMBER) {
            return $tok[1];
        }

        return null;
    }

    /**
     * Trozos de texto de un fichero: las cadenas literales y los pedazos de las cadenas con variables. [texto, línea].
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     *
     * @return array<int,array{0:string,1:int}>
     */
    private function pieces(array $t): array
    {
        $out = [];
        foreach ($t as $tok) {
            if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = [$this->decode($tok[1]), $tok[2]];
            } elseif ($tok[0] === T_ENCAPSED_AND_WHITESPACE) {
                $out[] = [$tok[1], $tok[2]];
            }
        }

        return $out;
    }

    /**
     * Fichas significativas (sin espacios ni comentarios), con los nombres con espacio de nombres unidos.
     *
     * @return array<int,array{0:?int,1:string,2:int}>
     */
    private function tokens(string $source): array
    {
        $names = [];
        foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $names[] = constant($constant);
            }
        }
        $out = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                $type = $token[0];
                $text = $token[1];
                $at = $line;
                $line += substr_count($text, "\n");
                if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (in_array($type, $names, true)) {
                    $type = T_STRING;
                    $text = substr_count($text, '\\') === 1 && $text[0] === '\\' ? substr($text, 1) : ltrim($text, '\\');
                }
                $out[] = [$type, $text, $at];
            } else {
                $out[] = [null, $token, $line];
            }
        }
        $norm = [];
        $count = count($out);
        for ($i = 0; $i < $count; $i++) {
            $tok = $out[$i];
            if ($tok[0] === T_NS_SEPARATOR && isset($out[$i + 1]) && $out[$i + 1][0] === T_STRING) {
                $last = count($norm) - 1;
                if ($last >= 0 && $norm[$last][0] === T_STRING) {
                    // «Foo\Bar»: se une con lo anterior. Una barra al principio de un nombre («\exec») simplemente se quita.
                    $norm[$last][1] .= '\\' . $out[$i + 1][1];
                    ++$i;
                }
                continue;
            }
            $norm[] = $tok;
        }

        return $norm;
    }

    /**
     * ¿Es la ficha $i la llamada a una función (no un método, ni una declaración)?
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     */
    private function isCall(array $t, int $i): bool
    {
        if ($t[$i][0] !== T_STRING || !isset($t[$i + 1]) || $t[$i + 1][1] !== '(') {
            return false;
        }
        if ($i > 0) {
            $prev = $t[$i - 1];
            if (in_array($prev[1], ['->', '::', '?->'], true) || in_array($prev[0], [T_FUNCTION, T_NEW, T_CONST, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Los argumentos de una llamada: rangos [desde, hasta) de fichas de cada uno y la posición del paréntesis que cierra.
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     *
     * @return array{args:array<int,array{0:int,1:int}>,close:int}
     */
    private function args(array $t, int $open): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;
        $n = count($t);
        for ($i = $open; $i < $n; $i++) {
            $x = $t[$i][1];
            if ($t[$i][0] === null && ($x === '(' || $x === '[' || $x === '{')) {
                ++$depth;
            } elseif ($t[$i][0] === T_CURLY_OPEN || $t[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($t[$i][0] === null && ($x === ')' || $x === ']' || $x === '}')) {
                --$depth;
                if ($depth === 0) {
                    if ($i > $start || $args !== []) {
                        $args[] = [$start, $i];
                    }

                    return ['args' => $args, 'close' => $i];
                }
            } elseif ($depth === 1 && $x === ',' && $t[$i][0] === null) {
                $args[] = [$start, $i];
                $start = $i + 1;
            }
        }

        return ['args' => $args, 'close' => $n];
    }

    /**
     * Rangos de fichas de cada sentencia (se corta en ; { y }).
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function statements(array $t): array
    {
        $ranges = [];
        $start = 0;
        foreach ($t as $i => $tok) {
            if ($tok[0] === null && ($tok[1] === ';' || $tok[1] === '{' || $tok[1] === '}')) {
                if ($i > $start) {
                    $ranges[] = [$start, $i];
                }
                $start = $i + 1;
            }
        }
        if (count($t) > $start) {
            $ranges[] = [$start, count($t)];
        }

        return $ranges;
    }

    /**
     * ¿Escribe fuera de la carpeta de datos? Se mira si la ruta sale de __DIR__, del directorio del sistema o es absoluta.
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     * @param array{0:int,1:int}                      $range
     */
    private function looksOutside(array $t, array $range): bool
    {
        for ($i = $range[0]; $i < $range[1]; $i++) {
            $tok = $t[$i];
            if (in_array($tok[0], [T_DIR, T_FILE], true)) {
                return true;
            }
            if ($tok[0] === T_STRING && in_array(strtolower($tok[1]), ['dirname', 'sys_get_temp_dir', 'getcwd', 'realpath', 'root', 'publicdir'], true)) {
                return true;
            }
            if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = $this->decode($tok[1]);
                if ($value !== '' && ($value[0] === '/' || $value[0] === '\\' || strpos($value, '..') !== false || preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ¿Imprime una variable sin pasarla por e() u otra función segura?
     *
     * @param array<int,array{0:?int,1:string,2:int}> $t
     */
    private function unsafeExpression(array $t, int $from, int $to): bool
    {
        $i = $from;
        while ($i < $to) {
            $tok = $t[$i];
            if ($tok[0] === T_STRING && isset($t[$i + 1]) && $t[$i + 1][1] === '(' && in_array(strtolower($tok[1]), self::SAFE_FUNCTIONS, true)) {
                $i = $this->args($t, $i + 1)['close'] + 1;
                continue;
            }
            if ($tok[0] === T_STRING && $tok[1] === 'Admin' && isset($t[$i + 3]) && $t[$i + 1][0] === T_DOUBLE_COLON && in_array($t[$i + 2][1], self::SAFE_ADMIN, true) && $t[$i + 3][1] === '(') {
                $i = $this->args($t, $i + 3)['close'] + 1;
                continue;
            }
            if ($tok[0] === T_VARIABLE && $tok[1] === '$this' && isset($t[$i + 3]) && $t[$i + 1][1] === '->' && in_array($t[$i + 2][1], self::SAFE_VIEW_METHODS, true) && $t[$i + 3][1] === '(') {
                $i = $this->args($t, $i + 3)['close'] + 1;
                continue;
            }
            if (in_array($tok[0], [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true)) {
                ++$i;
                while ($i < $to && ($t[$i][0] === T_VARIABLE || $t[$i][1] === '[' || $t[$i][1] === ']' || $t[$i][1] === '->' || $t[$i][0] === T_STRING || $t[$i][0] === T_CONSTANT_ENCAPSED_STRING || $t[$i][0] === T_LNUMBER)) {
                    ++$i;
                }
                continue;
            }
            if ($tok[0] === T_VARIABLE) {
                return true;
            }
            ++$i;
        }

        return false;
    }
}
