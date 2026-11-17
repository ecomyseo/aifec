<?php
/**
 * AIFEC — guarda: una columna cuyo nombre es palabra reservada de MariaDB/MySQL («cursor», «default», «select»…) no puede ir
 * sin comillas en el SQL escrito a mano. En SQLite (el de las pruebas) funciona y en el servidor no: el 14/11/2026 las
 * traducciones con IA fallaban con «error near 'cursor = ?…'» y el catálogo en inglés se quedó en 517 de 2.936.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;

test('ninguna columna con nombre reservado de MariaDB va sin comillas en el SQL escrito a mano', function (): void {
    $reserved = ['accessible', 'add', 'all', 'alter', 'analyze', 'and', 'asc', 'before', 'between', 'both', 'call', 'cascade', 'case',
        'change', 'check', 'collate', 'column', 'condition', 'constraint', 'continue', 'convert', 'create', 'cross', 'cursor', 'database',
        'default', 'delayed', 'delete', 'desc', 'describe', 'distinct', 'div', 'drop', 'each', 'else', 'exists', 'exit', 'explain', 'fetch',
        'for', 'force', 'foreign', 'from', 'fulltext', 'grant', 'group', 'having', 'if', 'ignore', 'index', 'inner', 'insert', 'interval',
        'into', 'is', 'join', 'key', 'keys', 'kill', 'leave', 'left', 'like', 'limit', 'lines', 'load', 'lock', 'long', 'loop', 'match',
        'mod', 'natural', 'not', 'null', 'on', 'optimize', 'option', 'or', 'order', 'out', 'outer', 'precision', 'primary', 'procedure',
        'purge', 'range', 'read', 'reads', 'references', 'regexp', 'release', 'rename', 'repeat', 'replace', 'require', 'restrict',
        'return', 'revoke', 'right', 'rlike', 'schema', 'select', 'separator', 'set', 'show', 'signal', 'spatial', 'table', 'then', 'to',
        'trigger', 'undo', 'union', 'unique', 'unlock', 'unsigned', 'update', 'usage', 'use', 'using', 'values', 'when', 'where', 'while',
        'with', 'write', 'xor', 'rank', 'row', 'rows', 'over', 'window'];
    $root = Autoload::root();
    $files = [];
    foreach (['core', 'modules', 'plugins'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php' && strpos(str_replace('\\', '/', $file->getPathname()), '/tests/') === false) {
                $files[] = $file->getPathname();
            }
        }
    }
    // Las columnas del esquema que se llaman como una palabra reservada.
    $columns = [];
    foreach ($files as $path) {
        if (strpos(str_replace('\\', '/', $path), '/migrations/') === false) {
            continue;
        }
        if (preg_match_all('/\$t->[a-zA-Z]+\(\'([a-z_]+)\'/', (string) file_get_contents($path), $m) > 0) {
            foreach ($m[1] as $column) {
                if (in_array($column, $reserved, true)) {
                    $columns[$column] = true;
                }
            }
        }
    }
    check(isset($columns['cursor']), 'la guarda conoce la columna «cursor» (si no, no mira nada)');
    $bad = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || preg_match('/\b(?:SELECT|UPDATE|INSERT|DELETE)\b/', $token[1]) !== 1) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                // La columna como columna: «cursor = ?», «, cursor,», «.cursor», «(cursor)»… y no entre comillas invertidas.
                if (preg_match('/(?<![`\w])' . $column . '\s*(?:=|,|\)|\s+(?:AS|FROM|IS|IN)\b)|[(.,]\s*' . $column . '(?![`\w])/i', $token[1]) === 1) {
                    $bad[] = basename($path) . ': ' . substr($token[1], 0, 120);
                }
            }
        }
    }
    same([], $bad);
});
