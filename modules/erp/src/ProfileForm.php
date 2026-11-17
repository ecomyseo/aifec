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

use Aifec\Kernel\App;

/**
 * La definición del formulario de un perfil de exportación o de importación: un solo formulario con pestañas.
 * Los textos van en inglés y el panel los traduce al pintar.
 */
final class ProfileForm
{
    /**
     * @return array<string,mixed> definición para Aifec\Admin\Form
     */
    public static function definition(App $app, string $direction): array
    {
        $import = $direction === 'import';
        $fields = [];
        $fields['name'] = ['label' => 'Name', 'rules' => 'required|max:120', 'tab' => 'erpmain', 'width' => 'md'];
        if (!$import) {
            $fields['kind'] = ['type' => 'select', 'label' => 'What to export', 'options' => Sources::KINDS, 'default' => 'orders', 'tab' => 'erpmain', 'width' => 'md'];
        }
        $formats = Profiles::FORMATS;
        if ($import) {
            unset($formats['xml']);
        }
        $fields['format'] = ['type' => 'select', 'label' => 'File format', 'options' => $formats, 'default' => 'csv', 'tab' => 'erpmain', 'width' => 'md'];
        $fields['enabled'] = ['type' => 'toggle', 'label' => $import ? 'Read it on its schedule' : 'Run it on its schedule', 'default' => 0, 'tab' => 'erpmain'];

        if (!$import) {
            $codes = ['_fixed' => 'Fixed value'];
            foreach (array_keys(Sources::KINDS) as $kind) {
                foreach (Sources::fields($kind) as $code => $def) {
                    $codes[$code] = $code;
                }
            }
            $fields['columns'] = [
                'type' => 'repeater', 'label' => 'Columns of the file', 'tab' => 'erpcolumns', 'add' => 'Add a column', 'translate' => false,
                'hint' => 'Empty = all the fields of the kind. The header is the name the ERP expects. A «Fixed value» column writes the same value in every row.',
                'fields' => [
                    'field' => ['type' => 'select', 'label' => 'Field', 'options' => $codes, 'translate' => false],
                    'header' => ['label' => 'Header name', 'rules' => 'nullable|max:60'],
                    'value' => ['label' => 'Fixed value', 'rules' => 'nullable|max:120'],
                ],
            ];
            $fields['csv_delimiter'] = ['type' => 'select', 'label' => 'Separator', 'options' => Formats::DELIMITERS, 'default' => ';', 'tab' => 'erpformat', 'show_if' => ['format' => 'csv'], 'width' => 'md'];
            $fields['csv_quote'] = ['type' => 'select', 'label' => 'Quotes', 'options' => ['"' => 'Double quote "', "'" => "Single quote '"], 'default' => '"', 'tab' => 'erpformat', 'show_if' => ['format' => 'csv'], 'width' => 'md'];
            $fields['csv_encoding'] = ['type' => 'select', 'label' => 'Encoding', 'options' => ['utf8' => 'UTF-8', 'utf8bom' => 'UTF-8 with BOM (Excel)', 'win1252' => 'Windows-1252'], 'default' => 'utf8', 'tab' => 'erpformat', 'show_if' => ['format' => 'csv'], 'width' => 'md'];
            $fields['csv_decimal'] = ['type' => 'select', 'label' => 'Decimal separator', 'options' => ['dot' => 'Dot (19.99)', 'comma' => 'Comma (19,99)'], 'default' => 'dot', 'tab' => 'erpformat', 'width' => 'md', 'hint' => 'JSON and XML always use the dot.'];
            $fields['csv_date_format'] = ['type' => 'select', 'label' => 'Date format', 'options' => ['iso' => '2026-11-12T12:00:00Z', 'sql' => '2026-11-12 12:00:00', 'date' => '2026-11-12', 'es' => '12/11/2026 13:00 (shop time)'], 'default' => 'iso', 'tab' => 'erpformat', 'width' => 'md', 'translate' => false];
            $fields['csv_eol'] = ['type' => 'select', 'label' => 'End of line', 'options' => ['lf' => 'LF (Linux)', 'crlf' => 'CRLF (Windows)'], 'default' => 'lf', 'tab' => 'erpformat', 'show_if' => ['format' => 'csv'], 'width' => 'md'];
            $fields['csv_header'] = ['type' => 'toggle', 'label' => 'First row with the names of the columns', 'default' => 1, 'tab' => 'erpformat', 'show_if' => ['format' => 'csv']];
            $fields['csv_safe'] = ['type' => 'toggle', 'label' => 'Protect spreadsheet formulas', 'default' => 1, 'tab' => 'erpformat', 'show_if' => ['format' => 'csv'], 'hint' => 'Writes an apostrophe before a text that starts with = + - or @.'];
            $fields['json_layout'] = ['type' => 'select', 'label' => 'JSON layout', 'options' => ['list' => 'One list [ … ]', 'lines' => 'One object per line'], 'default' => 'list', 'tab' => 'erpformat', 'show_if' => ['format' => 'json'], 'width' => 'md'];
            $fields['xml_root'] = ['label' => 'XML root tag', 'default' => 'items', 'rules' => 'nullable|max:40', 'tab' => 'erpformat', 'show_if' => ['format' => 'xml'], 'width' => 'md'];
            $fields['xml_item'] = ['label' => 'XML tag of each row', 'default' => 'item', 'rules' => 'nullable|max:40', 'tab' => 'erpformat', 'show_if' => ['format' => 'xml'], 'width' => 'md'];
            $fields['statuses'] = ['type' => 'checks', 'label' => 'Only orders with these statuses', 'tab' => 'erpfilter', 'show_if' => ['kind' => ['orders', 'order_lines']], 'translate' => true,
                'options_from' => ['table' => 'order_states', 'value' => 'code', 'label' => 'name', 'order' => 'position'], 'hint' => 'None ticked = all of them.'];
            $fields['only_new'] = ['type' => 'toggle', 'label' => 'Only what is new since the last run', 'default' => 0, 'tab' => 'erpfilter',
                'hint' => 'The first run starts from the moment the profile is created: it never sends what already existed.'];
        } else {
            $fields['col_sku'] = ['label' => 'Column with the SKU', 'rules' => 'required|max:60', 'tab' => 'erpcolumns', 'width' => 'md'];
            $fields['col_stock'] = ['label' => 'Column with the stock', 'rules' => 'nullable|max:60', 'tab' => 'erpcolumns', 'width' => 'md', 'hint' => 'Leave empty if the file has no stock.'];
            $fields['stock_mode'] = ['type' => 'select', 'label' => 'The stock column is', 'options' => ['absolute' => 'The new stock', 'delta' => 'A change (+5 or -3)'], 'default' => 'absolute', 'tab' => 'erpcolumns', 'width' => 'md'];
            $fields['col_price'] = ['label' => 'Column with the price', 'rules' => 'nullable|max:60', 'tab' => 'erpcolumns', 'width' => 'md'];
            $fields['col_sale_price'] = ['label' => 'Column with the offer price', 'rules' => 'nullable|max:60', 'tab' => 'erpcolumns', 'width' => 'md'];
            $fields['prices_include_tax'] = ['type' => 'toggle', 'label' => 'The prices in the file include tax', 'default' => 1, 'tab' => 'erpcolumns'];
        }

        $fields['destination'] = ['type' => 'select', 'label' => $import ? 'Where the file is' : 'Where the file goes', 'options' => Destinations::available($app, $import), 'default' => $import ? 'folder' : 'download', 'tab' => 'erpdest', 'width' => 'md'];
        $fields['dest_folder'] = ['label' => 'Folder name', 'rules' => 'nullable|max:60', 'tab' => 'erpdest', 'show_if' => ['destination' => 'folder'], 'width' => 'md', 'hint' => 'Inside the private folder of the site. Empty = a folder named after the profile number.'];
        $fields['dest_host'] = ['label' => 'Server', 'rules' => 'nullable|max:191', 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']], 'width' => 'md'];
        $fields['dest_port'] = ['type' => 'number', 'label' => 'Port', 'default' => 21, 'rules' => 'nullable|int|min:1|max:65535', 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']], 'width' => 'sm'];
        $fields['dest_user'] = ['label' => 'User', 'rules' => 'nullable|max:120', 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']], 'width' => 'md'];
        $fields['ftp_password'] = ['type' => 'secret', 'label' => 'Password', 'store' => false, 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']], 'width' => 'md', 'hint' => 'Empty = it does not change.'];
        $fields['dest_path'] = ['label' => 'Folder on the server', 'rules' => 'nullable|max:191', 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']], 'width' => 'md'];
        $fields['dest_passive'] = ['type' => 'toggle', 'label' => 'Passive mode', 'default' => 1, 'tab' => 'erpdest', 'show_if' => ['destination' => ['ftp', 'ftps']]];
        $fields['dest_url'] = ['label' => 'Address (https)', 'rules' => 'nullable|max:255', 'tab' => 'erpdest', 'show_if' => ['destination' => $import ? 'url' : 'post']];
        if (!$import) {
            $fields['post_token'] = ['type' => 'secret', 'label' => 'Bearer token', 'store' => false, 'tab' => 'erpdest', 'show_if' => ['destination' => 'post'], 'width' => 'md', 'hint' => 'Optional. Empty = it does not change.'];
            $fields['dest_email'] = ['type' => 'email', 'label' => 'Email', 'rules' => 'nullable|max:191', 'tab' => 'erpdest', 'show_if' => ['destination' => 'email'], 'width' => 'md'];
        } else {
            $fields['dest_pattern'] = ['label' => 'File names', 'default' => '*.csv', 'rules' => 'nullable|max:60', 'tab' => 'erpdest', 'show_if' => ['destination' => ['folder', 'ftp', 'ftps']], 'width' => 'md', 'hint' => 'For example stock-*.csv. Read files are moved to a «processed» folder.'];
        }

        $fields['schedule'] = ['type' => 'select', 'label' => 'When', 'options' => Profiles::SCHEDULES, 'default' => 'manual', 'tab' => 'erpwhen', 'width' => 'md'];
        $fields['schedule_every'] = ['type' => 'number', 'label' => 'Minutes', 'rules' => 'nullable|int|min:5|max:1440', 'tab' => 'erpwhen', 'show_if' => ['schedule' => 'minutes'], 'width' => 'sm'];
        $fields['schedule_at'] = ['label' => 'Time (HH:MM, shop time)', 'rules' => 'nullable|max:5', 'tab' => 'erpwhen', 'show_if' => ['schedule' => 'daily'], 'width' => 'sm', 'attrs' => ['placeholder' => '03:30']];

        $tabs = ['erpmain' => 'Profile'];
        if ($import) {
            $tabs += ['erpcolumns' => 'Columns to read', 'erpdest' => 'Source', 'erpwhen' => 'When'];
        } else {
            $tabs += ['erpcolumns' => 'Columns', 'erpformat' => 'File format', 'erpfilter' => 'What to include', 'erpdest' => 'Destination', 'erpwhen' => 'When'];
        }

        return ['tabs' => $tabs, 'fields' => $fields];
    }
}
