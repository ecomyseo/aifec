<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Ai\Prompt;
use Aifec\Kernel\App;

/**
 * El asistente que aconseja como un dependiente, con IA y sin ella. Lo que decide el código, no el modelo:
 *
 *  - Antes de preguntar a la IA (prestep): si preguntan por un producto, se lee su ficha (get_product) y se le pasa al modelo; si la
 *    necesidad es vaga, se leen las categorías de la tienda (list_categories, una vez por conversación, guardadas en el estado,
 *    clave «cats»); la necesidad en curso (Need) va en la nota del turno; un botón de afinar pulsado ya trae sus productos; y el tope
 *    de palabras del turno (corto en la compra, largo para hablar de un producto o aconsejar).
 *  - Después (finish): si el texto no habla del producto por el que se preguntó («aquí tienes los detalles» sin detalles, una
 *    presentación del asistente), se cuenta lo que dice su ficha; se quita el «¿te gustaría añadirlo al carrito?» del final; y tras
 *    añadir al carrito no se piden datos personales si el cliente no ha dicho que quiere comprar, pagar o terminar.
 *  - Los botones de respuesta rápida (suggest): los del modelo que pasaron la validación o, si no hay, los que tocan según lo que se ha
 *    enseñado (Replies).
 *  - Sin IA (rules): «háblame de X» cuenta su ficha; un mensaje que afina («soy hombre») afina la búsqueda anterior si casa con una
 *    categoría o da resultados con la necesidad, y si no pregunta con las categorías como botones; una necesidad vaga, «otra cosa» o
 *    «seguir mirando» enseñan las categorías que más venden como botones.
 */
final class Advice
{
    /** Los mensajes en los que se aconseja o se habla de un producto: tope de palabras largo. */
    private const LONG = ['about', 'vague', 'refine', 'new', 'chip', 'browse'];

    /** Categorías que se guardan de la tienda, como mucho. */
    private const MAX_CATS = 40;

    /** Botones de categoría que se ofrecen sin IA, como mucho. */
    private const CATEGORY_CHIPS = 4;

    /** @var App */
    private $app;

    /** @var Tools */
    private $tools;

    /** @var ToolRunner */
    private $runner;

    /** @var Answer */
    private $answer;

    /** @var array<string,mixed> */
    private $state;

    /** @var array<string,mixed> el contexto de las herramientas */
    private $context;

    /** @var array<int,array{id:int,name:string}>|null las categorías de la tienda, si ya se conocen */
    private $cats;

    /** @var int[] las que más venden, de la que más a la que menos */
    private $popular = [];

    /** @var array<string,mixed>|null el producto del que se habla en esta respuesta (lo que devolvió get_product) */
    public $product;

    /**
     * @param array<string,mixed> $state   el estado de la conversación
     * @param array<string,mixed> $context el de las herramientas (cart, message…)
     */
    public function __construct(App $app, Tools $tools, ToolRunner $runner, Answer $answer, array $state, array $context)
    {
        $this->app = $app;
        $this->tools = $tools;
        $this->runner = $runner;
        $this->answer = $answer;
        $this->state = $state;
        $this->context = $context;
        $saved = isset($state['cats']) && is_array($state['cats']) ? $state['cats'] : null;
        if ($saved !== null && isset($saved['list']) && is_array($saved['list'])) {
            $this->cats = self::cleanCats($saved['list']);
            foreach (isset($saved['pop']) && is_array($saved['pop']) ? $saved['pop'] : [] as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $this->popular[] = (int) $id;
                }
            }
        }
    }

    /** El tope de palabras de una respuesta a este tipo de mensaje. */
    public static function words(string $kind): int
    {
        return in_array($kind, self::LONG, true) ? AiBrain::WORDS_LONG : AiBrain::WORDS_SHORT;
    }

    /**
     * Las categorías de la tienda (id y nombre, las de primer nivel y sus hijas). La primera vez en la conversación se leen con
     * list_categories; después, del estado.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function categories(): array
    {
        if ($this->cats === null) {
            $result = $this->runner->run('list_categories', []);
            $list = [];
            foreach (isset($result['categories']) && is_array($result['categories']) ? $result['categories'] : [] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $list[] = $node;
                foreach (isset($node['children']) && is_array($node['children']) ? $node['children'] : [] as $child) {
                    $list[] = $child;
                }
            }
            $this->cats = self::cleanCats($list);
            $this->popular = [];
            foreach (isset($result['popular']) && is_array($result['popular']) ? $result['popular'] : [] as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $this->popular[] = (int) $row['id'];
                }
            }
        }

        return $this->cats;
    }

    /** ¿Se conocen ya las categorías (del estado o de esta respuesta)? */
    public function knowsCategories(): bool
    {
        return $this->cats !== null;
    }

    /**
     * Lo que se guarda de las categorías en el estado (clave «cats»), o null si no se han leído.
     *
     * @return array{list:array<int,array{id:int,name:string}>,pop:int[]}|null
     */
    public function stateCats(): ?array
    {
        return $this->cats === null ? null : ['list' => $this->cats, 'pop' => $this->popular];
    }

    /**
     * Antes de preguntar a la IA: lo que la tienda ya sabe y hace con este mensaje. Devuelve las notas para el modelo (en inglés).
     *
     * @param array<string,mixed>      $need la necesidad en curso, ya con este mensaje (Need::apply)
     * @param array<string,mixed>|null $chip el botón pulsado (Replies::clicked)
     *
     * @return string[]
     */
    public function prestep(string $message, string $kind, array $need, ?array $chip): array
    {
        $notes = [];
        $about = 0;
        if ($chip !== null && isset($chip['about'])) {
            $about = (int) $chip['about'];
        } elseif ($chip !== null) {
            $items = $this->chipItems($chip);
            $this->runner->preset($items);
            $rows = [];
            foreach ($items as $item) {
                $rows[] = ['id' => (int) $item['id'], 'name' => (string) $item['name'], 'price' => (string) $item['price']];
            }
            $json = (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $notes[] = 'The customer tapped one of the buttons the shop offered. The shop has already chosen the products it leads to (they are shown as cards unless you call show_products): say in a few words why they fit; do not search again unless they ask for something else.' . "\n" . Prompt::fence('button_products', $json);
        } elseif ($kind === 'about') {
            $about = ProductTalk::target($message, $this->state, $this->finder());
        }
        if ($about > 0) {
            $product = $this->runner->run('get_product', ['id' => $about]);
            $this->product = isset($product['error']) ? null : $product;
        }
        if ($this->product !== null) {
            $notes[] = 'The customer asks about this product. Describe it with its data below, in your own short sentences and in up to ' . AiBrain::WORDS_LONG . ' words: what it is and who it is for, up to five key features as a short list, its options (and which are left), its price (and the previous one if it is on sale) and whether it is in stock. If the data has no description, say "the product page only says…" and offer to compare it or show similar ones. Do not introduce yourself and do not offer to add it to the cart.' . "\n" . Prompt::fence('product', self::productJson($this->product));
        } elseif ($kind === 'about') {
            $notes[] = 'The customer asks about a product but it is not clear which one: if they named it, search for it and call get_product; otherwise ask which one.';
        }
        if (in_array($kind, ['vague', 'browse', 'reset'], true) || ($kind === 'refine' && !isset($need['s']))) {
            $this->categories();
        }
        $note = Need::note($need, $kind, $this->cats !== null ? $this->cats : [], $this->names());
        if ($note !== '') {
            $notes[] = $note;
        }
        if (self::words($chip !== null ? 'chip' : $kind) !== AiBrain::WORDS_SHORT) {
            // El tope corto ya lo dice la instrucción; el largo se dice en el turno en que vale.
            $notes[] = 'Length of this answer: at most ' . self::words($chip !== null ? 'chip' : $kind) . ' words.';
        }
        $this->runner->advise(in_array($kind, ['vague', 'refine', 'chip'], true), isset($need['dropped']) ? $need['dropped'] : []);

        return $notes;
    }

    /**
     * Después de que la IA haya contestado: lo que la tienda no deja pasar.
     */
    public function finish(string $message): void
    {
        $answer = $this->answer;
        if ($this->product !== null && !ProductTalk::covers($answer->text, $this->product)) {
            // «Aquí tienes los detalles» sin detalles, o el asistente presentándose: se cuenta lo que dice la ficha. (Su tarjeta solo
            // sale si el modelo la elige con show_products, como siempre.)
            $answer->text = ProductTalk::describe($this->product);
        }
        if (!RuleBrain::asksToAdd($message)) {
            $kept = TextGuard::dropCartPush($answer->text);
            if ($kept !== '') {
                $answer->text = $kept;
            }
        }
        $buying = RuleBrain::wantsToBuy($message) || !empty($this->state['flow']['active']);
        if ($answer->cartAdded && !$buying && TextGuard::asksPersonal($answer->text)) {
            // Se ha añadido algo y nadie ha dicho que quiera pagar: los datos personales no se piden todavía.
            $kept = TextGuard::dropPersonalAsk($answer->text);
            $answer->text = $kept !== '' ? $kept : $this->addedLine();
        }
    }

    /**
     * Los botones de respuesta rápida de una respuesta con IA: los del modelo que pasaron la validación o, si no propuso ninguno, los
     * que tocan según lo que se ha enseñado. Nada si ya hay (los de elegir opción, los de confirmar el pedido).
     */
    public function suggest(): void
    {
        $answer = $this->answer;
        if ($answer->pick !== null || $answer->suggestions !== []) {
            return;
        }
        if ($answer->proposed !== []) {
            $answer->suggestions = array_slice($answer->proposed, 0, Replies::MAX);

            return;
        }
        $this->contextChips();
    }

    /**
     * Los botones según lo que se acaba de hacer: tras añadir, ir a la caja, ver el carrito o seguir mirando; tras un producto, sus
     * opciones, parecidos, más barato y el envío; tras una lista, los de afinar.
     */
    public function contextChips(): void
    {
        $answer = $this->answer;
        if ($answer->cartAdded) {
            $answer->suggestions = Replies::afterAdd();

            return;
        }
        if ($answer->wrote) {
            $answer->suggestions = [t('Show my cart'), t('Go to checkout')];

            return;
        }
        $product = $this->product;
        if ($product === null && count($answer->cards) === 1) {
            $data = $this->tools->call('get_product', ['id' => (int) $answer->cards[0]['id']], $this->context);
            $product = isset($data['error']) ? null : $data;
        }
        if ($product !== null && count($answer->cards) <= 1) {
            list($chips, $meta) = Replies::forProduct($product, $this->tools, $this->context);
        } elseif (count($answer->cards) >= 2) {
            list($chips, $meta) = Replies::refine($answer->cards, $this->runner->itemCategories(), $this->money());
            if ($chips === []) {
                $chips = [t('Shipping costs')];
            }
        } else {
            return;
        }
        $answer->suggestions = array_slice($chips, 0, Replies::MAX);
        $answer->chipMeta = array_intersect_key($meta, array_flip($answer->suggestions));
    }

    // ------------------------------------------------------------------ sin IA

    /** Sin IA, tras una lista: los botones de afinar (dos como mucho) delante de los de siempre. */
    public function refineFirst(): void
    {
        list($chips, $meta) = Replies::refine($this->answer->cards, $this->runner->itemCategories(), $this->money());
        $chips = array_slice($chips, 0, 2);
        if ($chips === []) {
            return;
        }
        $this->answer->suggestions = array_slice(array_values(array_unique(array_merge($chips, $this->answer->suggestions))), 0, Replies::MAX);
        $this->answer->chipMeta = array_intersect_key($meta, array_flip($chips));
    }

    /**
     * Sin IA: un botón de los que se ofrecieron (afinar, parecidos, más barato, ver tallas). false si ya no lleva a nada.
     *
     * @param array<string,mixed> $chip
     */
    public function rulesChip(array $chip): bool
    {
        if (isset($chip['about'])) {
            return $this->rulesAbout((int) $chip['about']);
        }
        $items = $this->chipItems($chip);
        if ($items === []) {
            return false;
        }
        $this->show($items, t('These are the ones for «{label}»:', ['label' => (string) $chip['label']]));

        return true;
    }

    /**
     * Sin IA: lo que hace el consejo con un mensaje que las reglas no han atendido. false si no es cosa suya (se busca como siempre).
     *
     * @param array<string,mixed> $need
     */
    public function rules(string $message, string $kind, array $need): bool
    {
        switch ($kind) {
            case 'about':
                $id = ProductTalk::target($message, $this->state, $this->finder());

                return $id > 0 && $this->rulesAbout($id);
            case 'refine':
                return $this->rulesRefine($message, $need);
            case 'vague':
                $this->askCategories(t('I can help you choose. What kind of product are you looking for? These are the sections that sell the most:'));

                return true;
            case 'reset':
                $this->askCategories(t('Of course. What are you looking for now? These are the sections that sell the most:'));

                return true;
            case 'browse':
                $this->askCategories(t('What else can I show you? These are the sections that sell the most:'));

                return true;
            default:
                return false;
        }
    }

    // ------------------------------------------------------------------ interior

    /** «Háblame de X» sin IA: lo que dice su ficha, su tarjeta y sus botones. */
    private function rulesAbout(int $id): bool
    {
        $product = $this->runner->run('get_product', ['id' => $id]);
        if (isset($product['error'])) {
            return false;
        }
        $this->product = $product;
        $this->answer->text = ProductTalk::describe($product);
        $this->answer->addCard($product);
        $this->contextChips();

        return true;
    }

    /**
     * «Soy hombre», «más barato», «en negro» después de una búsqueda, sin IA: si casa con una categoría, la búsqueda anterior dentro
     * de ella (o la categoría); si no, la necesidad con lo que acaba de decir; y si nada da resultados, se pregunta con las categorías.
     * Nunca se busca el mensaje tal cual.
     *
     * @param array<string,mixed> $need
     */
    private function rulesRefine(string $message, array $need): bool
    {
        $need = Need::clean($need);
        if (!isset($need['s'])) {
            $this->askCategories(t('What are you looking for? Tell me the product or choose a section:'));

            return true;
        }
        $max = isset($need['max']) ? (int) $need['max'] : 0;
        if (!empty($need['cheaper'])) {
            $prices = [];
            foreach (isset($this->state['cards']) && is_array($this->state['cards']) ? $this->state['cards'] : [] as $card) {
                if (is_array($card) && isset($card['p']) && (int) $card['p'] > 0) {
                    $prices[] = (int) $card['p'];
                }
            }
            if ($prices !== []) {
                $cheapest = min($prices) - 1;
                $max = $max > 0 ? min($max, $cheapest) : $cheapest;
            }
        }
        $dropped = array_fill_keys(isset($need['dropped']) ? $need['dropped'] : [], true);
        $category = $this->matchCategory(Need::refineTerms($message), $message);
        if ($category !== null) {
            $items = [];
            foreach ($this->search(['query' => $need['s'], 'limit' => 8] + ($max > 0 ? ['max_price_cents' => $max] : []), true) as $item) {
                if (self::inCategory($item, $category['id']) && !isset($dropped[(int) $item['id']])) {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                foreach ($this->search(['category_id' => $category['id'], 'limit' => 8] + ($max > 0 ? ['max_price_cents' => $max] : []), true) as $item) {
                    if (!isset($dropped[(int) $item['id']]) && ($max <= 0 || (int) $item['price_cents'] <= $max)) {
                        $items[] = $item;
                    }
                }
            }
            if ($items !== []) {
                $this->show($items, t('These are the ones in «{category}»:', ['category' => $category['name']]));

                return true;
            }
        }
        $query = Need::query($need);
        $items = [];
        foreach ($this->search(['query' => $query, 'limit' => 8] + ($max > 0 ? ['max_price_cents' => $max] : []), false) as $item) {
            if (!isset($dropped[(int) $item['id']])) {
                $items[] = $item;
            }
        }
        if ($items !== []) {
            $this->show($items, t('These match «{q}»:', ['q' => $query]));

            return true;
        }
        $this->askCategories(t('I found nothing for «{q}». What exactly are you looking for? These are the sections of the shop:', ['q' => $query]));
        $this->answer->unanswered = true;

        return true;
    }

    /**
     * Pregunta enseñando como botones las categorías que más venden; cada botón lleva sus productos (nunca ninguno).
     */
    private function askCategories(string $text): void
    {
        $this->answer->text = $text;
        $chips = [];
        $meta = [];
        foreach ($this->topCategories(self::CATEGORY_CHIPS + 2) as $cat) {
            if (count($chips) >= self::CATEGORY_CHIPS || mb_strlen($cat['name'], 'UTF-8') > Replies::MAX_CHARS) {
                continue;
            }
            $found = $this->tools->call('search_products', ['category_id' => $cat['id'], 'limit' => 6], $this->context);
            $ids = [];
            foreach (isset($found['items']) && is_array($found['items']) ? $found['items'] : [] as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[] = (int) $item['id'];
                }
            }
            if ($ids !== []) {
                $chips[] = $cat['name'];
                $meta[$cat['name']] = ['cat' => $cat['id'], 'ids' => $ids];
            }
        }
        $this->answer->suggestions = $chips;
        $this->answer->chipMeta = $meta;
    }

    /**
     * Las categorías que más venden primero y, detrás, las demás de la tienda.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function topCategories(int $n): array
    {
        $all = $this->categories();
        $byId = [];
        foreach ($all as $cat) {
            $byId[$cat['id']] = $cat;
        }
        $out = [];
        foreach ($this->popular as $id) {
            if (isset($byId[$id])) {
                $out[$id] = $byId[$id];
            }
        }
        foreach ($all as $cat) {
            if (!isset($out[$cat['id']])) {
                $out[$cat['id']] = $cat;
            }
        }

        return array_slice(array_values($out), 0, $n);
    }

    /**
     * La categoría con la que casa lo que afina: la de esa persona («Hombre», «Caballero», «Ropa de mujer») o una que se llame como
     * una palabra del mensaje.
     *
     * @param array<string,mixed> $terms
     *
     * @return array{id:int,name:string}|null
     */
    private function matchCategory(array $terms, string $message): ?array
    {
        $cats = $this->categories();
        $group = isset($terms['group']) ? (string) $terms['group'] : '';
        if ($group !== '') {
            foreach ($cats as $cat) {
                foreach (explode(' ', RuleBrain::norm($cat['name'])) as $word) {
                    if (Need::group($word) === $group) {
                        return $cat;
                    }
                }
            }
        }
        foreach (explode(' ', RuleBrain::norm($message)) as $word) {
            if (mb_strlen($word, 'UTF-8') < 4) {
                continue;
            }
            foreach ($cats as $cat) {
                $name = RuleBrain::norm($cat['name']);
                if ($name === $word || strpos(' ' . $name, ' ' . mb_substr($word, 0, 5, 'UTF-8')) !== false) {
                    return $cat;
                }
            }
        }

        return null;
    }

    /**
     * Los productos de una búsqueda por las herramientas (con su registro): con $anyKind también lo parecido.
     *
     * @param array<string,mixed> $input
     *
     * @return array<int,array<string,mixed>>
     */
    private function search(array $input, bool $anyKind): array
    {
        $result = $this->runner->run('search_products', $input);
        $kind = isset($result['answer_kind']) ? (string) $result['answer_kind'] : '';
        if (isset($result['error']) || ($kind !== 'exact' && !($anyKind && in_array($kind, ['relaxed', 'related'], true)))) {
            return [];
        }

        return array_values(array_filter(isset($result['items']) && is_array($result['items']) ? $result['items'] : [], 'is_array'));
    }

    /**
     * Enseña estos productos con un texto y los botones que les tocan.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function show(array $items, string $text): void
    {
        $this->answer->text = $text;
        $this->runner->preset($items);
        foreach (array_slice($items, 0, Answer::MAX_CARDS) as $item) {
            $this->answer->addCard($item);
        }
        $this->contextChips();
    }

    /**
     * Los productos a los que lleva un botón: los suyos (los que tenía cuando se ofreció) menos los descartados y, si ya no queda
     * ninguno y era de una categoría, los de esa categoría.
     *
     * @param array<string,mixed> $chip
     *
     * @return array<int,array<string,mixed>>
     */
    private function chipItems(array $chip): array
    {
        $need = Need::clean(isset($this->state['need']) ? $this->state['need'] : []);
        $dropped = array_fill_keys(isset($need['dropped']) ? $need['dropped'] : [], true);
        $ids = [];
        foreach (isset($chip['ids']) && is_array($chip['ids']) ? $chip['ids'] : [] as $id) {
            if (!isset($dropped[(int) $id])) {
                $ids[] = (int) $id;
            }
        }
        $items = $ids === [] ? [] : (new ShopTools($this->app))->cardsFor($ids);
        if ($items === [] && isset($chip['cat'])) {
            $items = $this->search(['category_id' => (int) $chip['cat'], 'limit' => Answer::MAX_CARDS], true);
        }

        return array_slice($items, 0, Answer::MAX_CARDS);
    }

    /** El buscador del producto por el que se pregunta: la herramienta sin el ejecutor (no pone fichas ni cuenta como búsqueda). */
    private function finder(): callable
    {
        $tools = $this->tools;
        $context = $this->context;

        return static function (string $query) use ($tools, $context): array {
            return $tools->call('search_products', ['query' => $query, 'limit' => 3], ['own_words' => false] + $context);
        };
    }

    /** «Añadido a tu carrito: Silla. Total: 59,90 €», con lo que hicieron las herramientas. */
    private function addedLine(): string
    {
        $lines = [];
        $total = '';
        foreach ($this->runner->writeLog() as $entry) {
            if ($entry['name'] === 'cart_add') {
                $lines[] = t('Added to your cart: {name}.', ['name' => (string) $entry['label']]);
            }
            if ((string) $entry['total'] !== '') {
                $total = (string) $entry['total'];
            }
        }
        if ($total !== '') {
            $lines[] = t('Total: {total}', ['total' => $total]);
        }

        return $lines !== [] ? implode(' ', $lines) : t('Added to your cart.');
    }

    /**
     * Los nombres de los productos que ya han salido en la conversación, por id.
     *
     * @return array<int,string>
     */
    private function names(): array
    {
        $out = [];
        foreach (['shown', 'cards'] as $key) {
            foreach (isset($this->state[$key]) && is_array($this->state[$key]) ? $this->state[$key] : [] as $row) {
                if (is_array($row) && isset($row['id'], $row['n'])) {
                    $out[(int) $row['id']] = (string) $row['n'];
                }
            }
        }

        return $out;
    }

    /** Cómo se escribe un importe de la tienda («60,00 €»). */
    private function money(): callable
    {
        $currency = strtoupper(trim($this->app->settings()->str('shop.currency', 'EUR')));
        $currency = preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'EUR';
        $i18n = $this->app->i18n();

        return static function (int $cents) use ($i18n, $currency): string {
            return $i18n->money($cents, $currency);
        };
    }

    /**
     * ¿Es el producto de esta categoría?
     *
     * @param array<string,mixed> $item
     */
    private static function inCategory(array $item, int $category): bool
    {
        foreach (isset($item['categories']) && is_array($item['categories']) ? $item['categories'] : [] as $cat) {
            if (is_array($cat) && isset($cat['id']) && (int) $cat['id'] === $category) {
                return true;
            }
        }

        return false;
    }

    /**
     * La ficha de un producto para el modelo, sin lo que solo sirve para pintar.
     *
     * @param array<string,mixed> $p
     */
    private static function productJson(array $p): string
    {
        $keep = ['id', 'name', 'summary', 'description', 'facts', 'attributes', 'options', 'price', 'compare_price', 'from', 'has_variants',
            'delivery', 'categories', 'rating', 'brand'];
        $data = [];
        foreach ($keep as $key) {
            if (isset($p[$key]) && $p[$key] !== '' && $p[$key] !== []) {
                $data[$key] = $p[$key];
            }
        }
        $availability = isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : [];
        $data['available'] = (isset($availability['status']) ? (string) $availability['status'] : '') !== 'out_of_stock';
        $data['availability'] = isset($availability['label']) ? (string) $availability['label'] : '';
        if (isset($data['description']) && is_string($data['description'])) {
            $data['description'] = Prompt::truncate($data['description'], 900);
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Las categorías guardadas, limpias: id y nombre.
     *
     * @param array<int,mixed> $list
     *
     * @return array<int,array{id:int,name:string}>
     */
    private static function cleanCats(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $row) {
            if (!is_array($row) || !isset($row['id'], $row['name']) || (int) $row['id'] <= 0 || trim((string) $row['name']) === '' || isset($seen[(int) $row['id']])) {
                continue;
            }
            $seen[(int) $row['id']] = true;
            $out[] = ['id' => (int) $row['id'], 'name' => mb_substr(trim((string) $row['name']), 0, 60, 'UTF-8')];
            if (count($out) >= self::MAX_CATS) {
                break;
            }
        }

        return $out;
    }
}
