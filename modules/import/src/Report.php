<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;
use Aifec\Util\Csv;

/**
 * El informe de una migración: contadores por tipo, lo que no tiene equivalente, lo que hay que revisar a mano, las
 * diferencias de la comprobación final, el resultado de la comprobación con muestras (Verify) y la descarga en CSV de import_log.
 *
 * Nunca lleva un secreto: las líneas de import_log ya se escriben con los secretos tapados (Context::note) y aquí no se
 * enseña ningún registro entero. Los textos de «revisa a mano» van en inglés, con marcadores {nombre}, para pasarlos
 * por t().
 *
 * Las notas que apuntan los escritores (import_log.reason) llegan ya compuestas, en inglés y con sus valores dentro: «The coupon «X» of
 * the order was not brought…». Para enseñarlas en castellano, translateNote() las compara con las plantillas de TEMPLATES (la misma frase con
 * marcadores en lugar de los valores) y las traduce con los valores puestos; las frases enteras se traducen directamente. Todas están
 * declaradas en declared() para que el extractor de cadenas del sistema las vea.
 */
final class Report
{
    /** Filas de import_log que se leen de una vez al armar el CSV. */
    private const CSV_PAGE = 500;

    /** Las partes de la comprobación final y su título, en el orden en que se enseñan. */
    private const SECTIONS = [
        'counts' => 'Totals', 'states' => 'Orders by state', 'years' => 'Orders by year', 'products' => 'Products picked at random',
        'prices' => 'Prices with tax', 'orders' => 'Orders picked at random', 'addresses' => 'Old addresses',
    ];

    /**
     * Las notas de los escritores que llevan valores dentro, con un marcador por valor. Cada una está también en lang/es.php y en declared().
     * Los marcadores {what}, {entity} y {review} se traducen aparte (son palabras o listas de frases).
     */
    private const TEMPLATES = [
        'The lines of the order did not add up to its total: a price adjustment line of {amount} cents was added so that they do',
        'The {entity} has no title',
        'Some texts were longer than their field and were cut: {fields}',
        'none of the {what} it was limited to were brought',
        'Some of the {what} the coupon was limited to were not brought',
        'A negative {what} was left at 0',
        'An image inside the content could not be brought and still points to the old shop: {url}',
        'The coupon «{code}» of the order was not brought from this source: its use was not counted',
        'The default zone «{name}» was switched off: the zones of the source are used instead',
        'The file «{name}» of the product could not be brought',
        'The invoice number «{number}» already exists here: it was kept inside the order only',
        'The invoice «{number}» was kept inside the order only: {why}',
        'The order number «{base}» already existed here: it was brought as «{prefixed}»',
        'The record could not be written: {why}',
        'The state chosen for «{state}» does not exist here: its meaning was used instead',
        'There is no text in the main language: the one in «{lang}» was used',
        'WordPress shortcodes have no equivalent here: the text «[{text}]» was left in the content',
        'it has a restriction that cannot be set here («{item}»)',
        'The coupon lost a feature that does not exist here («{item}»)',
        'The coupon was brought inactive, because here it would be more generous than in the source: {review}',
        'Amount limits of «{method}»',
        'Payment method «{method}»',
        'Brand {field}',
        'An image could not be brought: {why}',
        'The old address «{path}» is already used by another page and was left alone',
        'The old address «{path}» has no new address to point to',
        'The old address «{path}» already redirects somewhere else and was left alone',
        'Could not link the record: {why}',
        'The writer failed: {why}',
        'This last step could not be completed: {why}',
    ];

    /** Marcadores cuyo valor es una palabra que también se traduce. */
    private const WORDS = ['what', 'entity'];

    /** @var array<int,array{0:string,1:string,2:string[]}>|null plantillas ya convertidas en expresiones regulares */
    private static $matchers;

    /** @var App */
    private $app;

    /** @var Runs */
    private $runs;

    public function __construct(App $app, Runs $runs)
    {
        $this->app = $app;
        $this->runs = $runs;
    }

    /**
     * Lo que enseñan la pantalla de progreso y el informe.
     *
     * @param array<string,mixed> $run fila ya descodificada de import_runs
     *
     * @return array<string,mixed>
     */
    public function progress(array $run): array
    {
        $state = isset($run['cursor']) && is_array($run['cursor']) ? $run['cursor'] : [];
        $plan = isset($state['plan']) && is_array($state['plan']) ? array_values($state['plan']) : [];
        $pos = isset($state['pos']) ? (int) $state['pos'] : 0;
        $totals = isset($state['totals']) && is_array($state['totals']) ? $state['totals'] : [];
        $counters = isset($run['counters']) && is_array($run['counters']) ? $run['counters'] : [];
        $labels = Types::labels();
        $open = in_array($run['status'], ['running', 'paused'], true);
        $writing = (string) $run['phase'] === 'write';

        $types = [];
        $zero = ['read' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'skipped' => 0];
        $sum = $zero;
        $weight = 0.0;
        foreach ($plan as $index => $type) {
            $type = (string) $type;
            $row = isset($counters[$type]) && is_array($counters[$type]) ? $counters[$type] : [];
            $row = array_merge($zero, array_map('intval', $row));
            $total = array_key_exists($type, $totals) && $totals[$type] !== null ? (int) $totals[$type] : null;
            if ($index < $pos || !$writing) {
                $state2 = 'done';
            } elseif ($index === $pos) {
                $state2 = $open ? 'current' : 'waiting';
            } else {
                $state2 = 'waiting';
            }
            if ($run['status'] === 'cancelled' || $run['status'] === 'failed') {
                $state2 = $index < $pos || !$writing ? 'done' : 'waiting';
            }
            $percent = null;
            if ($state2 === 'done') {
                $percent = 100;
            } elseif ($total !== null && $total > 0) {
                $percent = (int) min(99, floor($row['read'] * 100 / $total));
            } elseif ($state2 === 'waiting') {
                $percent = 0;
            }
            $weight += $percent === null ? 0 : $percent;
            foreach (array_keys($sum) as $key) {
                $sum[$key] += $row[$key];
            }
            $types[] = ['type' => $type, 'label' => isset($labels[$type]) ? $labels[$type] : $type, 'counters' => $row, 'total' => $total, 'state' => $state2, 'percent' => $percent];
        }
        $overall = $plan === [] ? 0 : (int) floor($weight / count($plan) * 0.9);
        if (!$writing) {
            $overall = max($overall, 90);
        }
        if ($run['status'] === 'done') {
            $overall = 100;
        }

        $skipped = [];
        foreach (isset($state['skipped_types']) && is_array($state['skipped_types']) ? $state['skipped_types'] : [] as $type => $why) {
            $skipped[] = [
                'type' => (string) $type, 'label' => isset($labels[$type]) ? $labels[$type] : (string) $type,
                'text' => isset($why['text']) ? (string) $why['text'] : 'Not brought', 'vars' => isset($why['vars']) && is_array($why['vars']) ? $why['vars'] : [],
            ];
        }

        return ['types' => $types, 'skipped_types' => $skipped, 'totals' => $sum, 'percent' => $overall];
    }

    /**
     * El informe entero.
     *
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    public function build(array $run): array
    {
        $progress = $this->progress($run);
        $counters = isset($run['counters']) && is_array($run['counters']) ? $run['counters'] : [];
        $unmapped = [];
        $source = isset($run['unmapped']['reader']) && is_array($run['unmapped']['reader']) ? $run['unmapped']['reader'] : [];
        foreach ($source as $item) {
            $unmapped[] = $item + ['origin' => 'source'];
        }
        foreach (isset($run['unmapped']['writers']) && is_array($run['unmapped']['writers']) ? $run['unmapped']['writers'] : [] as $item) {
            $unmapped[] = $item + ['origin' => 'here'];
        }
        $checks = isset($run['checksums']) && is_array($run['checksums']) ? $run['checksums'] : [];
        $diffs = isset($checks['diffs']) && is_array($checks['diffs']) ? $checks['diffs'] : [];
        $summary = $this->verifySummary($run);

        return [
            'run' => $run,
            'types' => $progress['types'],
            'skipped_types' => $progress['skipped_types'],
            'totals' => $progress['totals'],
            'percent' => $progress['percent'],
            'unmapped' => $unmapped,
            'review' => $this->review($run, $counters, $source, $progress['skipped_types'], $diffs, $summary),
            'diffs' => $diffs,
            'checksums' => $checks,
            'verify' => $summary === null ? null : ['summary' => $summary, 'sections' => $this->verifySections($run)],
            'log_counts' => $this->runs->logCounts((int) $run['id']),
        ];
    }

    /**
     * Las líneas de import_log como CSV (separador «;», con la protección de fórmulas de Csv).
     */
    public function csv(int $runId): string
    {
        $runs = $this->runs;
        $rows = (function () use ($runs, $runId): \Generator {
            $after = 0;
            while (true) {
                $page = $runs->logs($runId, '', '', self::CSV_PAGE, $after);
                if ($page === []) {
                    return;
                }
                foreach ($page as $row) {
                    $after = (int) $row['id'];
                    yield [(string) $row['type'], (string) $row['source_id'], (string) $row['result'], (string) $row['reason'], (string) $row['created_at']];
                }
            }
        })();

        return Csv::toString(['type', 'source_id', 'result', 'reason', 'created_at'], $rows);
    }

    // ------------------------------------------------------------------ la comprobación final

    /**
     * Cuánto se comprobó y cuánto no cuadra; null si la migración no hizo la comprobación (un simulacro, o una anterior a esta versión).
     *
     * @param array<string,mixed> $run
     *
     * @return array{ok:bool,checked:int,wrong:int,error:?string,at:string}|null
     */
    public function verifySummary(array $run): ?array
    {
        $verify = isset($run['checksums']['verify']) && is_array($run['checksums']['verify']) ? $run['checksums']['verify'] : null;
        if ($verify === null) {
            return null;
        }

        return [
            'ok' => !empty($verify['ok']), 'checked' => isset($verify['checked']) ? (int) $verify['checked'] : 0, 'wrong' => isset($verify['wrong']) ? (int) $verify['wrong'] : 0,
            'error' => isset($verify['error']) && is_string($verify['error']) ? $verify['error'] : null, 'at' => isset($verify['at']) ? (string) $verify['at'] : '',
        ];
    }

    /**
     * Las filas de la comprobación, listas para enseñar.
     *
     * @param array<string,mixed> $run
     * @param string              $mode «people» (castellano y con la moneda de la tienda), «csv» (castellano y con decimales) o «text» (inglés y con decimales: la consola)
     *
     * @return array<int,array{section:string,label:string,source:string,here:string,ok:bool,record:string,link:string,notes:string[]}>
     */
    public function verifyRows(array $run, string $mode = 'text'): array
    {
        $verify = isset($run['checksums']['verify']) && is_array($run['checksums']['verify']) ? $run['checksums']['verify'] : [];
        $out = [];
        foreach (isset($verify['rows']) && is_array($verify['rows']) ? $verify['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vars = isset($row['vars']) && is_array($row['vars']) ? $row['vars'] : [];
            $label = (string) $row['label'];
            $notes = [];
            foreach (isset($row['notes']) && is_array($row['notes']) ? $row['notes'] : [] as $note) {
                if (is_array($note)) {
                    $reason = isset($note['reason']) ? (string) $note['reason'] : '';
                    $notes[] = (isset($note['id']) && $note['id'] !== '' ? '#' . $note['id'] . ': ' : '') . ($mode === 'text' ? $reason : self::translateNote($reason));
                } else {
                    $notes[] = $mode === 'text' ? (string) $note : self::translateNote((string) $note);
                }
            }
            $out[] = [
                'section' => (string) $row['section'],
                'label' => $mode === 'text' ? strtr($label, self::braces($vars)) : t($label, $vars),
                'source' => $this->figure($row, 'source', $mode),
                'here' => $this->figure($row, 'here', $mode),
                'ok' => !empty($row['ok']),
                'record' => isset($row['record']) ? (string) $row['record'] : '',
                'link' => isset($row['link']) ? (string) $row['link'] : '',
                'notes' => $notes,
            ];
        }

        return $out;
    }

    /**
     * La comprobación agrupada por partes para la pantalla «Resultado».
     *
     * @param array<string,mixed> $run
     *
     * @return array<int,array{key:string,title:string,rows:array<int,array<string,mixed>>}>
     */
    public function verifySections(array $run): array
    {
        $grouped = [];
        foreach ($this->verifyRows($run, 'people') as $row) {
            $grouped[$row['section']][] = $row;
        }
        $out = [];
        foreach (self::SECTIONS as $key => $title) {
            if (isset($grouped[$key])) {
                $out[] = ['key' => $key, 'title' => t($title), 'rows' => $grouped[$key]];
            }
        }

        return $out;
    }

    /**
     * La comprobación como CSV descargable: una línea por cosa comprobada, con lo que decía el origen, lo que hay aquí y si cuadra.
     *
     * @param array<string,mixed> $run
     */
    public function verifyCsv(array $run): string
    {
        $rows = [];
        foreach ($this->verifyRows($run, 'csv') as $row) {
            $rows[] = [$row['section'], $row['label'], $row['source'], $row['here'], $row['ok'] ? 'ok' : 'WRONG', $row['record'], implode(' | ', $row['notes'])];
        }

        return Csv::toString(['section', 'check', 'source', 'here', 'result', 'record', 'notes'], $rows);
    }

    /**
     * Una cifra de una fila: cuenta, importe (con la moneda o con decimales) o texto.
     *
     * @param array<string,mixed> $row
     */
    private function figure(array $row, string $key, string $mode): string
    {
        $value = array_key_exists($key, $row) ? $row[$key] : null;
        if ($value === null) {
            return '-';
        }
        $kind = isset($row['kind']) ? (string) $row['kind'] : 'count';
        if ($kind === 'cents') {
            return $mode === 'people' ? money((int) $value) : self::decimal((int) $value);
        }
        if ($kind === 'text') {
            $vars = $key === 'here' && isset($row['here_vars']) && is_array($row['here_vars']) ? $row['here_vars'] : [];

            return $mode === 'text' ? strtr((string) $value, self::braces($vars)) : t((string) $value, $vars);
        }

        return (string) (int) $value;
    }

    /** Un importe en céntimos como «100.00». */
    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $vars
     *
     * @return array<string,string>
     */
    private static function braces(array $vars): array
    {
        $out = [];
        foreach ($vars as $key => $value) {
            $out['{' . $key . '}'] = (string) $value;
        }

        return $out;
    }

    // ------------------------------------------------------------------ las notas de los escritores, en castellano

    /**
     * Una nota de import_log (compuesta por un escritor, en inglés) en el idioma de quien la lee.
     */
    public static function translateNote(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        foreach (self::matchers() as $matcher) {
            if (preg_match($matcher[0], $text, $found) !== 1) {
                continue;
            }
            $vars = [];
            foreach ($matcher[2] as $i => $name) {
                $value = (string) $found[$i + 1];
                if (in_array($name, self::WORDS, true)) {
                    $value = t($value);
                } elseif ($name === 'review') {
                    $value = implode('; ', array_map([self::class, 'translateNote'], explode('; ', $value)));
                }
                $vars[$name] = $value;
            }

            return t($matcher[1], $vars);
        }

        return t($text);
    }

    /**
     * Las plantillas de TEMPLATES como expresiones regulares: la más larga primero, para que una más corta no se lleve lo de otra.
     *
     * @return array<int,array{0:string,1:string,2:string[]}>
     */
    private static function matchers(): array
    {
        if (self::$matchers !== null) {
            return self::$matchers;
        }
        $templates = self::TEMPLATES;
        usort($templates, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        $out = [];
        foreach ($templates as $template) {
            $parts = preg_split('/\{([a-z_]+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
            $regex = '';
            $names = [];
            foreach (is_array($parts) ? $parts : [] as $i => $part) {
                if ($i % 2 === 0) {
                    $regex .= preg_quote($part, '/');
                } else {
                    $regex .= '(.+?)';
                    $names[] = $part;
                }
            }
            $out[] = ['/^' . $regex . '$/su', $template, $names];
        }
        self::$matchers = $out;

        return $out;
    }

    /**
     * Las plantillas, para las pruebas.
     *
     * @return string[]
     */
    public static function templates(): array
    {
        return self::TEMPLATES;
    }

    /**
     * NO SE LLAMA NUNCA. Declara las cadenas que los escritores pasan a note(), noteOnce(), result() y unmapped(): se componen en inglés
     * y se enseñan al leerlas (translateNote), así que el extractor de cadenas no las vería pasar por t() en el código. Cada una tiene su
     * traducción en lang/es.php; una prueba recorre src/Writers/ y falla si falta alguna.
     */
    private static function declared(): void
    {
        t('Totals');
        t('Orders by state');
        t('Orders by year');
        t('Products picked at random');
        t('Orders picked at random');
        t('A category of the post was not brought: the post was written without it');
        t('A coupon with this code already exists here and was left as it is');
        t('A credit note of the source was kept inside the order only: the invoices module cannot store it');
        t('A different cookie key of an old shop was already set in the customer settings and was left as it is');
        t('A line of the order refers to a product that was not brought: the line was kept with its name and reference');
        t('A menu for this location already exists here and was left as it is');
        t('A menu item could not be linked to anything that was brought and has no address of its own: it was left out');
        t('A menu item refers to something that was not brought: it was kept as a link to its old address');
        t('A pending order was brought on hold, so the automatic cancellation of unpaid orders does not touch it');
        t('A place of the zone has postcodes that are not valid and was left out');
        t('A price adjustment line was added: check the order by hand');
        t('A user with this email address already exists here and was left as it is');
        t('AIFEC has no translation for this language: it was not added');
        t('An address has no country and was left out');
        t('An address has no street and was left out');
        t('An administrator of the source was brought as a store manager (the option team_admins is off)');
        t('An image path is outside the files folder of the source and was not read');
        t('Another customer of the source already has this email address');
        t('Brands have no search engine texts here');
        t('Brought as drafts');
        t('Brought switched off and in test mode: set it up before you sell');
        t('Compound tax');
        t('Coupon brought inactive');
        t('Could not be read');
        t('Images are switched off in this migration');
        t('Imported as a normal tax: check by hand that the amount is right');
        t('It was edited here after it was brought and was left as it is');
        t('Only choice lists and colours can make variants: this attribute is not used for them here');
        t('Order whose lines did not add up');
        t('Password format that cannot be checked here');
        t('Post author');
        t('Private content was brought as a draft: there are no private pages or posts here');
        t('Private products');
        t('Some categories of the product were not brought and were left out');
        t('Some data of the product point to an attribute that was not brought and were left out');
        t('Some images of the product could not be brought');
        t('Some rates point to a zone that was not brought and were left out');
        t('Some related products were not brought and were left out');
        t('Some values of the data of the product were not brought and were left out');
        t('Some variants have attributes that were not brought and were left out');
        t('Taxes here are always worked out from the shipping address');
        t('The address of the redirect is empty');
        t('The author of a post is a user here: the name of the source was not kept');
        t('The brand of the product was not brought');
        t('The category has no name');
        t('The class already has rates for this country that were set by hand');
        t('The coupon code is empty or longer than 40 letters');
        t('The coupon would be more generous here than in the source: check it before switching it on');
        t('The currency was not changed: the shop already has orders in another currency');
        t('The customer already existed here: its empty fields were filled in and its addresses added; the password and the name were left as they are');
        t('The customer group of the source was not brought: the default group was used');
        t('The customer of the order was not brought: the order was linked to the customer with its email address');
        t('The email address is not valid');
        t('The file could not be brought');
        t('The images of the product could not be brought');
        t('The list of postcodes is not valid');
        t('The menu has more than two levels here: the deepest items were moved up to the second level');
        t('The old PrestaShop passwords need the cookie key of the old shop (option cookie_key) and it was not given: those customers have to reset their password');
        t('The order has no customer in the source: a guest customer was created for its email address');
        t('The order has no email address');
        t('The parent category is one of its own subcategories: it stays at the top');
        t('The parent category was not brought or would make a loop: the category was left at the top level');
        t('The parent category was not brought: it stays at the top');
        t('The parent page was not brought or would make a loop: the page was left at the top level');
        t('The person was brought inactive and without a password: switch them on and set a password before they can sign in');
        t('The product has no name');
        t('The product of the review was not brought');
        t('The rating is not between 1 and 5');
        t('The reduction does not leave a price below the product price: no sale was brought');
        t('The site does not have this language: its texts were not brought');
        t('The tax class of the product was not brought: it uses the default one');
        t('The tax class of this carrier was not brought: it uses the one in the tax settings');
        t('The tax class of this rate was not brought');
        t('The tax rate of the source is not known: the one of this shop was used');
        t('The variants have attributes that were not brought: the product was brought as a simple one');
        t('The way prices are written was not changed: the shop already has products of its own');
        t('The source writes prices without taxes with more than two decimals: here prices keep including taxes so that every product costs exactly the same');
        t('A price cannot be written to the cent with prices without taxes: it differs by a cent from the source');
        t('The stock was shared by all the variations in the source: here every variation has its own, so each one got the whole quantity');
        t('Some variations had no stock control in the source and have no stock here: here the stock is controlled for the whole product');
        t('Some texts were longer than their field and were cut: {fields}');
        t('Prices with tax');
        t('Prices with tax identical');
        t('Old addresses that work or redirect');
        t('Texts cut to fit their field');
        t('See the notes');
        t('The writer gave an unknown result');
        t('The writer returned nothing');
        t('There is already a redirect from this address to somewhere else');
        t('There is already a review of this customer for this product here');
        t('There is no handling fee here: it was added to the price of each rate');
        t('There is no payment method here equivalent to this one');
        t('There is no proof of consent (the subscriber has no consent date)');
        t('These customers have to reset their password');
        t('This kind of carrier does not exist here: it was brought switched off');
        t('This payment method has no equivalent here');
        t('This payment method has no limits by amount here');
        t('the customer the coupon was meant for was not brought');
        t('The lines of the order did not add up to its total: a price adjustment line of {amount} cents was added so that they do');
        t('The {entity} has no title');
        t('none of the {what} it was limited to were brought');
        t('Some of the {what} the coupon was limited to were not brought');
        t('A negative {what} was left at 0');
        t('An image inside the content could not be brought and still points to the old shop: {url}');
        t('The coupon «{code}» of the order was not brought from this source: its use was not counted');
        t('The default zone «{name}» was switched off: the zones of the source are used instead');
        t('The file «{name}» of the product could not be brought');
        t('The invoice number «{number}» already exists here: it was kept inside the order only');
        t('The invoice «{number}» was kept inside the order only: {why}');
        t('The order number «{base}» already existed here: it was brought as «{prefixed}»');
        t('The record could not be written: {why}');
        t('The state chosen for «{state}» does not exist here: its meaning was used instead');
        t('There is no text in the main language: the one in «{lang}» was used');
        t('WordPress shortcodes have no equivalent here: the text «[{text}]» was left in the content');
        t('it has a restriction that cannot be set here («{item}»)');
        t('The coupon lost a feature that does not exist here («{item}»)');
        t('The coupon was brought inactive, because here it would be more generous than in the source: {review}');
        t('Amount limits of «{method}»');
        t('Payment method «{method}»');
        t('Brand {field}');
        t('An image could not be brought: {why}');
        t('The old address «{path}» is already used by another page and was left alone');
        t('The old address «{path}» has no new address to point to');
        t('The old address «{path}» already redirects somewhere else and was left alone');
        t('Could not link the record: {why}');
        t('The writer failed: {why}');
        t('This last step could not be completed: {why}');
        t('page');
        t('post');
        t('products');
        t('categories');
        t('stock');
        t('price');
        // La comprobación final (las pone Verify en inglés).
        t('Variants');
        t('Orders in state «{state}»');
        t('Orders placed in {year}');
        t('Amount of the orders placed in {year}');
        t('Order {number} (#{id}): total');
        t('Order {number} (#{id}): tax');
        t('Product {product} (#{id}): price with tax');
        t('Product {product} (#{id}): sale price with tax');
        t('Product {product} (#{id}), variant {variant}: price with tax');
        t('Old address {path}');
        t('Works');
        t('Works at the same address');
        t('301 to {path}');
        t('It does not answer');
        t('The final check could not be completed');
    }

    // ------------------------------------------------------------------ revisa a mano

    /**
     * «Revisa a mano»: lo que la migración no puede dar por bueno sola.
     *
     * @param array<string,mixed>                       $run
     * @param array<string,mixed>                       $counters
     * @param array<int,array<string,mixed>>            $source   lo que el lector dijo que no trae
     * @param array<int,array<string,mixed>>            $skipped
     * @param array<int,array<string,mixed>>            $diffs
     * @param array{ok:bool,checked:int,wrong:int,error:?string,at:string}|null $verify
     *
     * @return array<int,array{level:string,text:string,vars:array<string,mixed>}>
     */
    private function review(array $run, array $counters, array $source, array $skipped, array $diffs, ?array $verify): array
    {
        $brought = static function (string $type) use ($counters): int {
            $c = isset($counters[$type]) && is_array($counters[$type]) ? $counters[$type] : [];

            return (int) (isset($c['created']) ? $c['created'] : 0) + (int) (isset($c['updated']) ? $c['updated'] : 0);
        };
        $items = [];
        if ($brought('payment_methods') > 0) {
            $items[] = ['level' => 'warn', 'text' => '{n} payment methods were brought switched off and in test mode: set up each one before you sell', 'vars' => ['n' => $brought('payment_methods')]];
        }
        $people = $brought('customers') + $brought('orders') + $brought('subscribers') + $brought('team') + $brought('settings');
        if ($people > 0) {
            $items[] = ['level' => 'info', 'text' => 'No email was sent during the migration. Check the outgoing mail settings and send yourself a test message before you sell', 'vars' => []];
        }
        if ($brought('team') > 0) {
            $items[] = ['level' => 'warn', 'text' => '{n} team members were brought inactive and without a password: switch on the ones that should come back and send them a password reset', 'vars' => ['n' => $brought('team')]];
        }
        foreach ($source as $item) {
            if (in_array((string) $item['kind'], ['module', 'plugin', 'extension'], true)) {
                $items[] = ['level' => 'warn', 'text' => 'The source uses «{name}», which has no replacement here', 'vars' => ['name' => (string) $item['name']]];
            }
        }
        $options = isset($run['options']) && is_array($run['options']) ? $run['options'] : [];
        $missing = [];
        foreach (isset($options['source_languages']) && is_array($options['source_languages']) ? $options['source_languages'] : [] as $lang) {
            if (is_string($lang) && !in_array(strtolower(substr($lang, 0, 2)), $this->app->languages(), true)) {
                $missing[] = $lang;
            }
        }
        if ($missing !== []) {
            $items[] = ['level' => 'warn', 'text' => 'These languages of the source are not set up here and their texts were not brought: {list}', 'vars' => ['list' => implode(', ', array_values(array_unique($missing)))]];
        }
        foreach ($skipped as $row) {
            $items[] = ['level' => 'info', 'text' => '«{type}» was not brought', 'vars' => ['type' => (string) $row['label']], 'why' => $row['text'], 'why_vars' => $row['vars']];
        }
        $conflicts = 0;
        $skippedRecords = 0;
        foreach ($counters as $row) {
            $conflicts += is_array($row) && isset($row['conflict']) ? (int) $row['conflict'] : 0;
            $skippedRecords += is_array($row) && isset($row['skipped']) ? (int) $row['skipped'] : 0;
        }
        if ($conflicts > 0) {
            $items[] = ['level' => 'warn', 'text' => '{n} records were edited here after being brought and were left as they are', 'vars' => ['n' => $conflicts]];
        }
        if ($skippedRecords > 0) {
            $items[] = ['level' => 'warn', 'text' => '{n} records could not be brought: see the list of notes', 'vars' => ['n' => $skippedRecords]];
        }
        if ($diffs !== []) {
            $items[] = ['level' => 'bad', 'text' => 'The final check found {n} differences between the source and this shop', 'vars' => ['n' => count($diffs)]];
        }
        if ($verify !== null && $verify['error'] !== null) {
            $items[] = ['level' => 'bad', 'text' => 'The check with samples could not be completed', 'vars' => []];
        } elseif ($verify !== null && $verify['wrong'] > 0) {
            $items[] = ['level' => 'bad', 'text' => '{n} things do not match what the source had: see the result', 'vars' => ['n' => $verify['wrong']]];
        }

        return $items;
    }
}
