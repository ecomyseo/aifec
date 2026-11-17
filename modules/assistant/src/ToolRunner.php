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

use Aifec\Ai\AiTool;
use Aifec\Kernel\App;

/**
 * Ejecuta herramientas para una respuesta y va dejando en ella lo que pasó: la herramienta usada (para el panel), si el carrito
 * cambió o se pidió el pago, qué productos han salido en esta conversación y qué opción hay que elegir. Lo comparten el modo con IA y
 * el modo por reglas, así los dos apuntan lo mismo.
 *
 * Las fichas de producto NO salen de lo que devuelve una búsqueda: las elige quien contesta con la herramienta «show_products» (que
 * solo acepta productos que hayan salido en un resultado de herramienta de esta conversación). Si no la usa, salen como mucho las
 * primeras de la última búsqueda, y solo si esa búsqueda acertó (answer_kind «exact»): sin acierto no hay fichas de relleno.
 */
final class ToolRunner
{
    /** Las herramientas de la caja que cambian los datos del pedido (anulan la confirmación esperada). */
    private const EDITS = ['checkout_set_customer', 'checkout_set_shipping', 'checkout_set_payment'];

    /** Datos que se guardan enmascarados en el registro de herramientas: nunca el valor. */
    private const PERSONAL = ['email', 'first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'text', 'note'];

    /** Fichas que se enseñan sin que el modelo las elija, como mucho. */
    public const DEFAULT_CARDS = 3;

    /** Productos que puede enseñar «show_products» de una vez. */
    public const SHOW_MAX = 6;

    /** @var App */
    private $app;

    /** @var Tools */
    private $tools;

    /** @var array<string,mixed> */
    private $context;

    /** @var Answer */
    private $answer;

    /** @var Timer */
    private $timer;

    /** @var callable|null lo que se dice mientras corre una herramienta (la ventana lo enseña) */
    private $onStatus;

    /** @var string */
    private $lastStatus = '';

    /** @var array<int,bool> los productos que han salido en un resultado de herramienta de esta conversación */
    private $seen = [];

    /** @var array<int,array{id:int,n:string}> los que ya se enseñaron en fichas en esta conversación */
    private $shown = [];

    /** @var array{kind:string,items:array<int,array<string,mixed>>}|null la última búsqueda de esta respuesta */
    private $lastSearch;

    /** @var bool el modelo ha elegido las fichas con show_products */
    private $showCalled = false;

    /** @var array<int,bool> los importes (céntimos) que han salido de las herramientas en esta respuesta */
    private $amounts = [];

    /** @var array<string,array<string,mixed>> las líneas (producto:variante) que ya se añadieron al carrito en esta respuesta y lo que dio cada una */
    private $added = [];

    /** @var array<int,array<string,mixed>> lo que cambió el carrito en esta respuesta (para componer la respuesta si el modelo no la escribe) */
    private $writeLog = [];

    /** @var string[] los botones de elegir opción que se ofrecieron en esta respuesta */
    private $pickChips = [];

    /** @var array<int,array{query:string,kind:string,items:array<int,array<string,mixed>>}> las búsquedas de esta respuesta, en orden */
    private $searchLog = [];

    /** @var array<int,array<int,array{id:int,name:string}>> las categorías de los productos que han salido en esta respuesta, por id */
    private $itemCats = [];

    /** @var array<int,int> el precio (céntimos) de los productos que han salido en esta respuesta, por id */
    private $itemPrices = [];

    /** @var array<string,bool> las cifras sueltas que han salido de las herramientas en esta respuesta («42», «39.5») */
    private $numbers = [];

    /** @var array<int,array<string,mixed>> las fichas que la tienda ya eligió para esta respuesta (un botón de afinar pulsado) */
    private $preset = [];

    /** @var bool una respuesta de consejo: si nadie elige las fichas, se mezclan las de todas las búsquedas */
    private $advising = false;

    /** @var array<int,array<string,mixed>> los productos de los que se ha hablado en esta respuesta (get_product), por identificador */
    private $talked = [];

    /** @var bool en esta respuesta ya se le dio al buscador la ocasión de llamar a su propia IA (una por respuesta) */
    private $searchAiSpent = false;

    /** @var array<int,bool> los productos que el cliente descartó («otro», «ese no»): no se vuelven a enseñar por su cuenta */
    private $dropped = [];

    /** @var int milisegundos que han tardado las herramientas */
    public $toolsMs = 0;

    /** @var int búsquedas de producto hechas */
    public $searches = 0;

    /** @var int productos exactos que dieron esas búsquedas */
    public $hits = 0;

    /**
     * @param array<string,mixed> $context cart, conversation_id, checkout_turn, seen (identificadores), shown (id y nombre)
     */
    public function __construct(App $app, Tools $tools, array $context, Answer $answer, ?Timer $timer = null)
    {
        $this->app = $app;
        $this->tools = $tools;
        $this->context = $context;
        $this->answer = $answer;
        $this->timer = $timer !== null ? $timer : new Timer();
        foreach (isset($context['seen']) && is_array($context['seen']) ? $context['seen'] : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $this->seen[(int) $id] = true;
            }
        }
        foreach (isset($context['shown']) && is_array($context['shown']) ? $context['shown'] : [] as $row) {
            if (is_array($row) && isset($row['id'], $row['n'])) {
                $this->shown[] = ['id' => (int) $row['id'], 'n' => (string) $row['n']];
            }
        }
        // Lo que ya se añadió en esta respuesta con otro ejecutor (la IA añadió y la respuesta sigue por reglas): no se añade dos veces.
        foreach (isset($context['added']) && is_array($context['added']) ? $context['added'] : [] as $key => $result) {
            if (is_string($key) && is_array($result)) {
                $this->added[$key] = $result;
            }
        }
    }

    /**
     * Las líneas que se han añadido al carrito en esta respuesta (producto:variante → lo que devolvió la herramienta).
     *
     * @return array<string,array<string,mixed>>
     */
    public function addedLines(): array
    {
        return $this->added;
    }

    /**
     * Lo que cambió el carrito en esta respuesta, en orden: name (la herramienta), label (el producto con su opción), qty y total.
     *
     * @return array<int,array<string,mixed>>
     */
    public function writeLog(): array
    {
        return $this->writeLog;
    }

    /** La ventana quiere saber en qué anda la herramienta que corre (se llama con el texto ya traducido). */
    public function onStatus(?callable $callback): void
    {
        $this->onStatus = $callback;
    }

    /** La herramienta «show_products» tal como se le ofrece al modelo. */
    public static function showTool(): AiTool
    {
        return new AiTool(
            'show_products',
            'Choose the product cards the shop shows to the customer with your answer (1 to ' . self::SHOW_MAX . '). Only products that came out in a tool result of this conversation are accepted: any other id is discarded. Show only the ones you talk about; with none, nothing is shown.',
            self::showSchema()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function showSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::SHOW_MAX, 'items' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'required' => ['ids'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> lo que devuelve la herramienta, o ['error' => texto]
     */
    public function run(string $name, array $input): array
    {
        $this->announce($name);
        $started = $this->timer->ms();
        try {
            if ($name === 'show_products') {
                return $this->showProducts($input);
            }

            return $name === 'suggest_replies' ? $this->suggestReplies($input) : $this->execute($name, $input);
        } finally {
            $this->toolsMs += max(0, $this->timer->ms() - $started);
        }
    }

    /**
     * ¿Esta herramienta solo consulta? Las que eligen fichas o botones, y las que leen el catálogo, la tienda, el envío o el carrito.
     * No las que cambian el carrito o los datos de la compra, ni ninguna de la caja (su conversación va paso a paso).
     */
    public function reads(string $name): bool
    {
        if ($name === 'show_products' || $name === 'suggest_replies') {
            return true;
        }

        return $this->can($name) && !$this->tools->writes($name) && !in_array($name, self::EDITS, true) && strpos($name, 'checkout_') !== 0;
    }

    /** ¿Existe esta herramienta ahora? (la compra en el chat se puede apagar con «assistant.checkout»). */
    public function can(string $name): bool
    {
        $all = $this->tools->all();

        return isset($all[$name]);
    }

    /** Los importes (céntimos) que han salido de las herramientas en esta respuesta. */
    public function amounts(): array
    {
        return array_keys($this->amounts);
    }

    /** Los identificadores de producto que han salido en resultados de herramienta (los de antes y los de esta respuesta). */
    public function seenIds(): array
    {
        return array_keys($this->seen);
    }

    /** ¿Eligió el modelo las fichas con show_products? */
    public function showCalled(): bool
    {
        return $this->showCalled;
    }

    /** ¿Cómo acabó la última búsqueda de esta respuesta? exact, relaxed, related, shop, none; vacío si no se buscó. */
    public function lastKind(): string
    {
        return $this->lastSearch !== null ? $this->lastSearch['kind'] : '';
    }

    /**
     * Pone las fichas de la última búsqueda cuando nadie eligió otras: las primeras $max, y solo si esa búsqueda acertó. Con
     * $anyKind (el modo por reglas, que presenta lo parecido como tal) también las de «relaxed» y «related».
     */
    public function presentSearch(int $max, bool $anyKind = false): void
    {
        if ($this->showCalled || $this->answer->cards !== []) {
            return;
        }
        if ($this->preset !== []) {
            // Un botón de afinar pulsado: las fichas a las que lleva ese botón (nunca ninguna).
            foreach (array_slice($this->preset, 0, Answer::MAX_CARDS) as $item) {
                $this->answer->addCard($item);
            }

            return;
        }
        if ($this->advising && count($this->searchLog) >= 2) {
            $this->presentMix(Answer::MAX_CARDS);

            return;
        }
        if ($this->lastSearch === null) {
            // Se habló de UN producto concreto sin buscar (get_product): su ficha acompaña a lo que se cuenta de él (las dos
            // fotos, el precio, las opciones y el botón). De varios no elige la tienda: para eso está show_products.
            if (count($this->talked) === 1) {
                $this->answer->addCard((array) reset($this->talked));
            }

            return;
        }
        $kind = $this->lastSearch['kind'];
        if ($kind !== 'exact' && !($anyKind && ($kind === 'relaxed' || $kind === 'related'))) {
            return;
        }
        foreach (array_slice($this->lastSearch['items'], 0, max(1, $max)) as $item) {
            $this->answer->addCard($item);
        }
    }

    /**
     * Una respuesta de consejo (necesidad vaga o que se afina): si nadie elige las fichas y hubo varias búsquedas, se enseñan
     * mezcladas (una de cada búsqueda por turno, lo agotado detrás) y nunca las que el cliente descartó.
     *
     * @param int[] $dropped
     */
    public function advise(bool $on, array $dropped = []): void
    {
        $this->advising = $on;
        $this->dropped = [];
        foreach ($dropped as $id) {
            $this->dropped[(int) $id] = true;
        }
    }

    /**
     * Las fichas que la tienda ya eligió para esta respuesta (las de un botón pulsado): salen si el modelo no elige otras.
     *
     * @param array<int,array<string,mixed>> $items tarjetas con el formato de los resultados de búsqueda
     */
    public function preset(array $items): void
    {
        $this->preset = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $this->preset[] = $item;
                $this->see($item);
                $this->noteItem($item);
            }
        }
    }

    /**
     * Las búsquedas de esta respuesta: query (normalizada), kind y los productos.
     *
     * @return array<int,array{query:string,kind:string,items:array<int,array<string,mixed>>}>
     */
    public function searchLog(): array
    {
        return $this->searchLog;
    }

    /** ¿Se ha usado (bien) esta herramienta en esta respuesta? */
    public function called(string $name): bool
    {
        foreach ($this->answer->toolLog as $entry) {
            if ((string) $entry['name'] === $name && !empty($entry['ok'])) {
                return true;
            }
        }

        return false;
    }

    /** Las consultas distintas que se han buscado en esta respuesta (normalizadas). */
    public function queries(): array
    {
        return array_values(array_unique(array_column($this->searchLog, 'query')));
    }

    /**
     * Las categorías de los productos que han salido en esta respuesta, por id.
     *
     * @return array<int,array<int,array{id:int,name:string}>>
     */
    public function itemCategories(): array
    {
        return $this->itemCats;
    }

    /**
     * Los precios (céntimos) de los productos que han salido en esta respuesta, por id.
     *
     * @return array<int,int>
     */
    public function itemPrices(): array
    {
        return $this->itemPrices;
    }

    /** Las cifras sueltas que han salido de las herramientas en esta respuesta. */
    public function numbers(): array
    {
        return array_map('strval', array_keys($this->numbers));
    }

    /**
     * Un resultado de herramienta como lo ve el modelo (sin lo que solo sirve para pintar).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function modelView(string $name, array $data): array
    {
        return ToolSummary::forModel($name, $data);
    }

    // ------------------------------------------------------------------ interior

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function execute(string $name, array $input): array
    {
        if ($name === 'search_products' && isset($this->context['refine']) && is_string($this->context['refine']) && $this->context['refine'] !== ''
            && isset($input['query']) && is_string($input['query']) && RuleBrain::norm($input['query']) === RuleBrain::norm($this->message())) {
            // Un mensaje que afina («soy hombre») no se busca tal cual: se busca la necesidad en curso con lo que acaba de decir.
            $input['query'] = mb_substr($this->context['refine'], 0, 120, 'UTF-8');
        }
        $turn = $this->turn();
        if ($turn !== null && ($this->tools->writes($name) || in_array($name, self::EDITS, true)) && $name !== 'checkout_place') {
            // Un cambio en el carrito o en los datos anula la espera de confirmación y su ficha, y el resumen ya pintado en esta
            // respuesta deja de valer: no se le enseña un botón de confirmar de un pedido que ya no es el revisado.
            $turn->disarm();
            $this->dropCard();
        }
        if ($name === 'cart_add') {
            $again = $this->duplicateAdd($input);
            if ($again !== null) {
                // La misma línea (producto y opción) en la misma respuesta: no se añade otra vez, aunque el modelo lo pida dos veces.
                $this->answer->toolLog[] = ['name' => $name, 'ok' => true, 'input' => self::redact($input), 'duplicate' => true];

                return $again;
            }
            $there = $this->inCart($input);
            if ($there !== null) {
                // La línea ya está en el carrito de un turno anterior y el cliente no ha pedido más: no se suma.
                $this->answer->toolLog[] = ['name' => $name, 'ok' => true, 'input' => self::redact($input), 'in_cart' => true];

                return $there;
            }
        }
        // Cuando el turno lo lleva el modelo, la frase ya la ha entendido él: el buscador puede llamar a su propia IA UNA vez por
        // respuesta (la primera búsqueda que no acierta) y en las demás solo usa lo que tenga guardado. 13/11/2026, en vivo:
        // cuatro búsquedas a 1-2 s cada una agotaban el tiempo del turno, el modelo no llegaba a escribir y la respuesta caía
        // a las reglas («soy hombre» → zapatillas). Sin modelo (reglas), el buscador sí puede usar la suya en cada búsqueda.
        $context = $this->context;
        if ($name === 'search_products' && $this->answer->mode === 'ai' && $this->searchAiSpent) {
            $context['by_model'] = true;
        }
        if ($name === 'search_products' && $this->answer->mode === 'ai') {
            // Con el modelo al mando, una búsqueda con pocos resultados trae además candidatos cercanos (ShopTools::nearby).
            $context['widen'] = true;
        }
        $result = $this->tools->call($name, $input, $context);
        if ($name === 'search_products' && is_array($result) && array_key_exists('ai_used', $result)) {
            $this->searchAiSpent = $this->searchAiSpent || $result['ai_used'] === true;
            unset($result['ai_used']);
        }
        if ($name === 'cart_add' && isset($result['need']) && $result['need'] === 'options' && (int) (isset($input['variant_id']) ? $input['variant_id'] : 0) === 0) {
            // Un producto con opciones: si el cliente ya dijo cuál («en negro», «la gris», «talla M»), se añade esa, una vez.
            $resolved = $this->resolveOptions($result);
            if ($resolved !== null) {
                $again = $this->duplicateAdd($resolved);
                if ($again !== null) {
                    $this->answer->toolLog[] = ['name' => $name, 'ok' => true, 'input' => self::redact($resolved), 'duplicate' => true];

                    return $again;
                }
                $there = $this->inCart($resolved);
                if ($there !== null) {
                    $this->answer->toolLog[] = ['name' => $name, 'ok' => true, 'input' => self::redact($resolved), 'in_cart' => true];

                    return $there;
                }
                $input = $resolved;
                $result = $this->tools->call($name, $input, $this->context);
            }
        }
        $ok = !isset($result['error']);
        $this->answer->toolLog[] = ['name' => $name, 'ok' => $ok, 'input' => self::redact($input)];
        if (!$ok) {
            if ($name === 'cart_add' && array_key_exists('pick', $result)) {
                // Pidió una opción que no hay: las que sí hay se le ofrecen con botones, y la que elija se añade.
                $pick = is_array($result['pick']) ? VariantPick::fromNeed($result['pick'], (int) (isset($result['pick']['qty']) ? $result['pick']['qty'] : 1)) : null;
                if ($pick !== null) {
                    $this->answer->pick = $pick;
                    foreach (VariantPick::chips($pick) as $chip) {
                        $this->answer->suggestions[] = $chip;
                        $this->pickChips[] = $chip;
                    }
                }
                unset($result['pick']);
            }

            return $result;
        }
        foreach (TextGuard::collect($result) as $cents) {
            $this->amounts[$cents] = true;
        }
        $this->collectNumbers($result);
        if ($name === 'search_products') {
            $result = $this->afterSearch($result, isset($input['query']) ? (string) $input['query'] : '');
        } elseif ($name === 'get_product') {
            $this->see($result);
            $this->noteItem($result);
            if (!isset($result['error']) && isset($result['id']) && (int) $result['id'] > 0) {
                $this->talked[(int) $result['id']] = $result;
            }
        } elseif ($name === 'compare_products') {
            foreach (isset($result['products']) && is_array($result['products']) ? $result['products'] : [] as $row) {
                $this->see(is_array($row) ? $row : []);
            }
        } elseif ($name === 'cart_view' || $name === 'cart_add' || $name === 'cart_update') {
            foreach (isset($result['lines']) && is_array($result['lines']) ? $result['lines'] : [] as $line) {
                $this->see(is_array($line) && isset($line['product_id']) ? ['id' => $line['product_id']] : []);
            }
        }
        if ($name === 'cart_add' && isset($result['need']) && $result['need'] === 'options') {
            // Un producto con variantes y sin decir cuál: no se ha tocado el carrito, se pregunta con botones.
            $pick = VariantPick::fromNeed($result, (int) (isset($result['qty']) ? $result['qty'] : 1));
            if ($pick !== null) {
                $message = $this->message();
                $narrow = $message !== '' ? VariantPick::resolve($pick, $message) : null;
                if ($narrow !== null && $narrow['status'] === 'many') {
                    // Ya dijo algo que deja pocas opciones: se le ofrecen solo esas.
                    $pick = $narrow['pick'];
                }
                $this->answer->pick = $pick;
                foreach (VariantPick::chips($pick) as $chip) {
                    $this->answer->suggestions[] = $chip;
                    $this->pickChips[] = $chip;
                }
            }

            return $result;
        }
        if ($this->tools->writes($name)) {
            $this->answer->wrote = true;
            $this->answer->pick = null;
            $this->dropPickChips();
            if ($name === 'cart_add') {
                $this->answer->cartAdded = true;
                $this->noteAdded($result);
            } else {
                $this->writeLog[] = ['name' => $name, 'label' => '', 'qty' => 0, 'total' => isset($result['cart']['total']) ? (string) $result['cart']['total'] : ''];
            }
            $count = isset($result['cart']['count']) ? (int) $result['cart']['count'] : null;
            $this->action(['type' => 'cart_changed', 'count' => $count]);
        }
        if ($name === 'checkout_set_customer') {
            foreach (isset($result['saved']) && is_array($result['saved']) ? $result['saved'] : [] as $key) {
                if (preg_match('/(?:^|\.)(?:line1|line2|postcode|city|region|country)$/D', (string) $key) === 1 || $key === 'address_id') {
                    $this->answer->addressChanged = true;
                    break;
                }
            }
        }
        if ($name === 'list_subscription_plans' && isset($result['url']) && !isset($result['error'])) {
            $this->action(['type' => 'subscriptions', 'url' => (string) $result['url']]);
        }
        if ($name === 'checkout_link' && isset($result['url'])) {
            $this->answer->checkout = true;
            $this->action(['type' => 'checkout', 'url' => (string) $result['url']]);
        }
        if ($name === 'checkout_review') {
            if (!empty($result['over_limit']) && isset($result['checkout_url']) && (string) $result['checkout_url'] !== '') {
                // Por encima del tope del chat: la compra sigue en la caja de la web.
                $this->answer->checkout = true;
                $this->action(['type' => 'checkout', 'url' => (string) $result['checkout_url']]);
            } elseif ($turn !== null && $turn->armed() && is_array($turn->card)) {
                // La tarjeta con el resumen y el botón «Confirmar pedido»: el modelo no la escribe, la pone el motor.
                $this->action($turn->card);
                if ($this->answer->suggestions === []) {
                    $this->answer->suggestions[] = t('Yes, I confirm');
                }
            }
        }

        return $result;
    }

    /** El mensaje que el cliente acaba de escribir (el entero, sin enmascarar), o vacío. */
    private function message(): string
    {
        return isset($this->context['message']) && is_string($this->context['message']) ? $this->context['message'] : '';
    }

    /**
     * Si esta misma línea ya se añadió en esta respuesta, lo que dio entonces (marcado como repetido); si no, null. Un producto pedido
     * sin opción cuando ya se añadió alguna de sus opciones en esta respuesta también cuenta como repetido.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|null
     */
    private function duplicateAdd(array $input): ?array
    {
        $product = (int) (isset($input['product_id']) ? $input['product_id'] : 0);
        $variant = (int) (isset($input['variant_id']) ? $input['variant_id'] : 0);
        $found = null;
        if (isset($this->added[$product . ':' . $variant])) {
            $found = $this->added[$product . ':' . $variant];
        } elseif ($variant === 0) {
            foreach ($this->added as $key => $result) {
                if (strpos((string) $key, $product . ':') === 0) {
                    $found = $result;
                    break;
                }
            }
        }
        if ($found === null) {
            return null;
        }

        return $found + ['already_added' => true, 'note' => 'This line was already added to the cart in this answer: it is there once. Do not add it again.'];
    }

    /**
     * ¿Pide el cliente MÁS unidades de algo de forma expresa? «otra», «otro más», «una más», «añade 2», «ponme dos», «one more»,
     * «another». Lo decide el código mirando el mensaje, no el modelo; «no hay otra más barata» no lo es.
     */
    public static function asksMore(string $message): bool
    {
        $n = RuleBrain::norm($message);
        if ($n === '' || preg_match('/\b(?:no|ni|sin|not|dont|never)\b(?:\s+\S+){0,2}\s+(?:otr[oa]s?|another|more|mas)\b/', $n) === 1) {
            return false;
        }
        $number = '(?:\d{1,2}|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|two|three|four|five|six|seven|eight|nine|ten)';

        return preg_match('/\b(?:otr[oa]s?|another|(?:un[oa]|' . $number . ') mas|(?:one|' . $number . ') more)\b/', $n) === 1
            || preg_match('/\b(?:anade\w*|agrega\w*|mete\w*|pon(?:me|le|les)?|suma\w*|add|put)\s+(?:otr[oa]s?\s+)?' . $number . '\b/', $n) === 1;
    }

    /**
     * Si la línea que se quiere añadir (producto y opción) YA está en el carrito de un turno anterior y el cliente no ha pedido
     * más en este mensaje, lo que se devuelve en lugar de sumar: «ya está en el carrito: N unidades», con la clave de la línea
     * para cambiar la cantidad con una cifra. null = se puede añadir. Solo en una conversación: el botón «añadir» de una ficha
     * (sin mensaje) es una orden expresa de la persona y suma.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>|null
     */
    private function inCart(array $input): ?array
    {
        if (!array_key_exists('message', $this->context) || self::asksMore($this->message())) {
            return null;
        }
        $product = (int) (isset($input['product_id']) ? $input['product_id'] : 0);
        $variant = (int) (isset($input['variant_id']) ? $input['variant_id'] : 0);
        if ($product <= 0) {
            return null;
        }
        $view = $this->tools->call('cart_view', [], $this->context);
        foreach (isset($view['lines']) && is_array($view['lines']) ? $view['lines'] : [] as $line) {
            if (!is_array($line) || (int) $line['product_id'] !== $product || (int) $line['variant_id'] !== $variant) {
                continue;
            }
            $qty = (int) $line['qty'];

            return [
                'already_in_cart' => true,
                'product_id' => $product,
                'variant_id' => $variant,
                'key' => (string) $line['key'],
                'name' => (string) $line['name'],
                'variant' => (string) $line['variant'],
                'qty_in_cart' => $qty,
                'cart' => ['count' => (int) $view['count'], 'total' => (string) $view['total'], 'total_cents' => (int) $view['total_cents']],
                'message' => 'Nothing was added: this product is already in the cart, ' . $qty . ' ' . ($qty === 1 ? 'unit' : 'units') . '. Tell the customer it is already there. Only if they ask for a different number of units, call cart_update with this key and that exact number.',
            ];
        }

        return null;
    }

    /**
     * Lo que dijo el cliente en este mensaje contra las opciones del producto: si dice una sola, los datos de cart_add con esa variante.
     *
     * @param array<string,mixed> $need lo que devolvió cart_add con need=options
     *
     * @return array<string,mixed>|null
     */
    private function resolveOptions(array $need): ?array
    {
        $message = $this->message();
        $qty = (int) (isset($need['qty']) ? $need['qty'] : 1);
        $pick = $message !== '' ? VariantPick::fromNeed($need, $qty) : null;
        if ($pick === null) {
            return null;
        }
        $r = VariantPick::resolve($pick, $message);

        return $r['status'] === 'one' ? ['product_id' => (int) $need['product_id'], 'variant_id' => $r['variant'], 'qty' => max(1, $qty)] : null;
    }

    /**
     * Apunta una línea añadida: no se vuelve a añadir en esta respuesta, y queda para componer lo que se le dice al cliente.
     *
     * @param array<string,mixed> $result lo que devolvió cart_add
     */
    private function noteAdded(array $result): void
    {
        $product = (int) (isset($result['product_id']) ? $result['product_id'] : 0);
        $variant = (int) (isset($result['variant_id']) ? $result['variant_id'] : 0);
        $this->added[$product . ':' . $variant] = $result;
        $label = (string) (isset($result['name']) ? $result['name'] : '');
        if (isset($result['variant']) && trim((string) $result['variant']) !== '') {
            $label .= ' (' . trim((string) $result['variant']) . ')';
        }
        $this->writeLog[] = [
            'name' => 'cart_add', 'label' => $label, 'qty' => (int) (isset($result['qty']) ? $result['qty'] : 1),
            'total' => isset($result['cart']['total']) ? (string) $result['cart']['total'] : '',
            // Lo que cuestan las unidades añadidas («2 × Silla a 89,00 € son 178,00 €»), calculado por la tienda.
            'unit' => isset($result['unit_price']) ? (string) $result['unit_price'] : '',
            'sum' => isset($result['added_total']) ? (string) $result['added_total'] : '',
        ];
    }

    /** Quita de las respuestas rápidas los botones de elegir opción: ya se añadió, no hay nada que elegir. */
    private function dropPickChips(): void
    {
        if ($this->pickChips === []) {
            return;
        }
        $chips = $this->pickChips;
        $this->answer->suggestions = array_values(array_filter($this->answer->suggestions, static function (string $chip) use ($chips): bool {
            return !in_array($chip, $chips, true);
        }));
        $this->pickChips = [];
    }

    /** Quien contesta ha elegido no enseñar ninguna ficha: no salen las de la última búsqueda. */
    public function showNone(): void
    {
        $this->showCalled = true;
        $this->answer->cards = [];
        $this->answer->toolLog[] = ['name' => 'show_products', 'ok' => true, 'input' => ['ids' => '']];
    }

    /**
     * «show_products»: las fichas las elige quien contesta, pero solo entre lo que ha salido de una herramienta.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function showProducts(array $input): array
    {
        $checked = SchemaCheck::check(self::showSchema(), $input);
        if (!$checked['ok']) {
            $this->answer->toolLog[] = ['name' => 'show_products', 'ok' => false, 'input' => []];

            return ['error' => t('The input is not valid') . ': ' . implode('; ', array_slice($checked['errors'], 0, 3))];
        }
        $wanted = is_array($checked['value']) && isset($checked['value']['ids']) && is_array($checked['value']['ids']) ? $checked['value']['ids'] : [];
        $valid = [];
        $ignored = 0;
        foreach (array_values(array_unique(array_map('intval', $wanted))) as $id) {
            if (isset($this->seen[$id])) {
                $valid[] = $id;
            } else {
                ++$ignored;
            }
        }
        $this->showCalled = true;
        $this->answer->cards = [];
        $cards = $valid === [] ? [] : (new ShopTools($this->app))->cardsFor($valid);
        $shown = [];
        foreach ($cards as $card) {
            $this->answer->addCard($card);
            $this->noteItem($card);
            $shown[] = (int) $card['id'];
        }
        $this->answer->toolLog[] = ['name' => 'show_products', 'ok' => true, 'input' => ['ids' => implode(',', $shown)]];
        $out = ['shown' => $shown, 'ignored' => $ignored];
        if ($ignored > 0) {
            $out['note'] = 'Some ids did not come out of a tool result in this conversation and were discarded.';
        }

        return $out;
    }

    /**
     * Lo que se apunta de una búsqueda: qué productos han salido, cómo acabó y, si no salió nada, lo que ya se había enseñado antes
     * en la conversación que casa con lo buscado («antes de decir que no tenemos algo, se mira también ahí»).
     *
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function afterSearch(array $result, string $query = ''): array
    {
        ++$this->searches;
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $kind = isset($result['answer_kind']) ? (string) $result['answer_kind'] : ($items === [] ? 'none' : 'exact');
        if ($items === [] && $kind !== 'shop') {
            $again = $this->alreadyShown((string) (isset($result['query']) ? $result['query'] : ''));
            if ($again !== []) {
                $items = (new ShopTools($this->app))->cardsFor($again);
                foreach ($items as $i => $item) {
                    $items[$i]['already_shown'] = true;
                }
                if ($items !== []) {
                    $kind = 'exact';
                    $result['items'] = $items;
                    $result['total'] = count($items);
                    $result['answer_kind'] = 'exact';
                    $result['note'] = 'These products were already shown to the customer earlier in this conversation.';
                }
            }
        }
        foreach ($items as $item) {
            $this->see(is_array($item) ? $item : []);
            $this->noteItem(is_array($item) ? $item : []);
        }
        foreach (isset($result['nearby']) && is_array($result['nearby']) ? $result['nearby'] : [] as $item) {
            // Los candidatos cercanos se pueden elegir con show_products, pero no son «lo encontrado»: ni cuentan como aciertos ni
            // salen como fichas de serie.
            $this->see(is_array($item) ? $item : []);
            $this->noteItem(is_array($item) ? $item : []);
        }
        if ($kind === 'exact') {
            $this->hits += count($items);
        }
        $this->lastSearch = ['kind' => $kind, 'items' => $kind === 'none' || $kind === 'shop' ? [] : array_values(array_filter($items, 'is_array'))];
        $this->searchLog[] = ['query' => RuleBrain::norm($query), 'kind' => $kind, 'items' => $this->lastSearch['items']];

        return $result;
    }

    /**
     * Las fichas de varias búsquedas mezcladas: una de cada búsqueda por turno (tipos distintos), sin repetir, lo que se puede comprar
     * delante y nada de lo que el cliente descartó. Solo las exactas de cada búsqueda: lo parecido no se presenta como lo pedido.
     */
    private function presentMix(int $max): void
    {
        $lists = [];
        foreach ($this->searchLog as $search) {
            if ($search['kind'] !== 'exact') {
                continue;
            }
            $buyable = [];
            $sold = [];
            foreach ($search['items'] as $item) {
                if (isset($this->dropped[(int) $item['id']])) {
                    continue;
                }
                if (empty($item['available'])) {
                    $sold[] = $item;
                } else {
                    $buyable[] = $item;
                }
            }
            $lists[] = array_merge($buyable, $sold);
        }
        $picked = [];
        for ($round = 0; count($picked) < $max; ++$round) {
            $any = false;
            foreach ($lists as $list) {
                if (!isset($list[$round])) {
                    continue;
                }
                $any = true;
                $id = (int) $list[$round]['id'];
                if (!isset($picked[$id]) && count($picked) < $max) {
                    $picked[$id] = $list[$round];
                }
            }
            if (!$any) {
                break;
            }
        }
        foreach ($picked as $item) {
            $this->answer->addCard($item);
        }
    }

    /**
     * Apunta las categorías y el precio de un producto que ha salido (para los botones de afinar).
     *
     * @param array<string,mixed> $item
     */
    private function noteItem(array $item): void
    {
        if (!isset($item['id']) || !is_numeric($item['id']) || (int) $item['id'] <= 0) {
            return;
        }
        $id = (int) $item['id'];
        if (isset($item['price_cents']) && is_numeric($item['price_cents'])) {
            $this->itemPrices[$id] = (int) $item['price_cents'];
        }
        $cats = [];
        if (isset($item['categories']) && is_array($item['categories'])) {
            foreach ($item['categories'] as $i => $category) {
                if (is_array($category) && isset($category['id'], $category['name'])) {
                    $cats[] = ['id' => (int) $category['id'], 'name' => (string) $category['name']];
                } elseif (is_string($category) && isset($item['category_ids'][$i]) && (int) $item['category_ids'][$i] > 0) {
                    // get_product trae los nombres y, aparte, sus identificadores.
                    $cats[] = ['id' => (int) $item['category_ids'][$i], 'name' => $category];
                }
            }
        }
        if ($cats !== []) {
            $this->itemCats[$id] = $cats;
        }
    }

    /**
     * Apunta las cifras sueltas de un resultado («42», «39.5»): un botón propuesto solo puede llevar cifras que hayan salido.
     *
     * @param mixed $data
     */
    private function collectNumbers($data): void
    {
        if (count($this->numbers) >= 600) {
            return;
        }
        if (is_array($data)) {
            foreach ($data as $value) {
                $this->collectNumbers($value);
            }

            return;
        }
        if (is_int($data)) {
            $this->numbers[(string) $data] = true;
        } elseif (is_string($data) && preg_match_all('/\d+(?:[.,]\d+)?/', $data, $m) > 0) {
            foreach ($m[0] as $number) {
                $this->numbers[str_replace(',', '.', $number)] = true;
            }
        }
    }

    /**
     * «suggest_replies»: los botones que propone el modelo, uno a uno por la validación de la tienda (Replies::check).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function suggestReplies(array $input): array
    {
        $checked = SchemaCheck::check(Replies::schema(), $input);
        if (!$checked['ok']) {
            $this->answer->toolLog[] = ['name' => 'suggest_replies', 'ok' => false, 'input' => []];

            return ['error' => t('The input is not valid') . ': ' . implode('; ', array_slice($checked['errors'], 0, 3))];
        }
        $wanted = is_array($checked['value']) && isset($checked['value']['replies']) && is_array($checked['value']['replies']) ? $checked['value']['replies'] : [];
        $allowed = array_merge($this->amounts(), TextGuard::amounts($this->message()));
        $prices = array_values($this->itemPrices);
        $accepted = [];
        $discarded = 0;
        foreach ($wanted as $chip) {
            $clean = is_string($chip) ? Replies::check($chip, $allowed, $this->numbers(), $prices) : null;
            if ($clean !== null && !$this->turnHasOptions() && preg_match('/\b(tallas?|sizes?|opci[oó]n(es)?|options?|colou?rs?|colores)\b/iu', $clean) === 1) {
                // «Ver tallas» de un producto que no tiene tallas ni ninguna otra opción: un botón que no lleva a nada (13/11/2026, en vivo).
                $clean = null;
            }
            if ($clean === null || in_array(mb_strtolower($clean, 'UTF-8'), array_map(static function (string $c): string {
                return mb_strtolower($c, 'UTF-8');
            }, $accepted), true)) {
                ++$discarded;
                continue;
            }
            if (count($accepted) < Replies::MAX) {
                $accepted[] = $clean;
            } else {
                ++$discarded;
            }
        }
        $this->answer->proposed = $accepted;
        $this->answer->toolLog[] = ['name' => 'suggest_replies', 'ok' => true, 'input' => ['replies' => implode(' | ', $accepted)]];

        return ['accepted' => $accepted, 'discarded' => $discarded];
    }

    /** ¿Alguno de los productos de esta respuesta (del que se habla, o los que salieron de buscar) tiene tallas, colores u otra opción? */
    private function turnHasOptions(): bool
    {
        $items = array_values($this->talked);
        foreach ($this->searchLog as $search) {
            foreach (isset($search['items']) && is_array($search['items']) ? $search['items'] : [] as $item) {
                $items[] = $item;
            }
        }
        if ($this->lastSearch !== null) {
            foreach ($this->lastSearch['items'] as $item) {
                $items[] = $item;
            }
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['has_variants']) || (isset($item['options']) && is_array($item['options']) && $item['options'] !== [])
                || (isset($item['option_values']) && is_array($item['option_values']) && $item['option_values'] !== [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los productos ya enseñados cuyo nombre contiene todas las palabras de peso de lo buscado.
     *
     * @return int[]
     */
    private function alreadyShown(string $query): array
    {
        $words = [];
        foreach (explode(' ', RuleBrain::norm($query)) as $word) {
            if (mb_strlen($word, 'UTF-8') >= 4) {
                $words[] = mb_substr($word, 0, 5, 'UTF-8');
            }
        }
        if ($words === []) {
            return [];
        }
        $found = [];
        foreach ($this->shown as $row) {
            $name = ' ' . RuleBrain::norm($row['n']);
            $all = true;
            foreach ($words as $stem) {
                if (strpos($name, ' ' . $stem) === false) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $found[] = $row['id'];
            }
        }

        return array_slice($found, 0, 5);
    }

    /**
     * Apunta que un producto ha salido en un resultado de herramienta.
     *
     * @param array<string,mixed> $item
     */
    private function see(array $item): void
    {
        if (isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] > 0) {
            $this->seen[(int) $item['id']] = true;
        }
    }

    /** Dice en qué anda la herramienta, una vez por cambio. */
    private function announce(string $name): void
    {
        if ($this->onStatus === null) {
            return;
        }
        $text = self::statusText($name);
        if ($text === '' || $text === $this->lastStatus) {
            return;
        }
        $this->lastStatus = $text;
        call_user_func($this->onStatus, $text);
    }

    /** Lo que se le enseña al cliente mientras una herramienta trabaja. */
    public static function statusText(string $tool): string
    {
        switch ($tool) {
            case 'search_products':
            case 'list_categories':
                return t('Searching the shop…');
            case 'get_product':
            case 'check_availability':
                return t('Looking at the product…');
            case 'shipping_quote':
            case 'checkout_shipping_options':
            case 'checkout_set_shipping':
                return t('Calculating the shipping…');
            case 'cart_add':
            case 'cart_update':
            case 'cart_view':
                return t('Updating your cart…');
            case 'checkout_set_customer':
            case 'checkout_status':
                return t('Saving your details…');
            case 'checkout_payment_options':
            case 'checkout_set_payment':
                return t('Looking at the payment methods…');
            case 'checkout_review':
            case 'checkout_place':
                return t('Preparing your order…');
            case 'order_status':
                return t('Looking up your order…');
            case 'store_info':
                return t('Looking at the shop information…');
            default:
                return '';
        }
    }

    /** Quita de esta respuesta la tarjeta de confirmar y su respuesta rápida. */
    private function dropCard(): void
    {
        $this->answer->actions = array_values(array_filter($this->answer->actions, static function (array $action): bool {
            return !isset($action['type']) || $action['type'] !== 'confirm_order';
        }));
        $yes = t('Yes, I confirm');
        $this->answer->suggestions = array_values(array_filter($this->answer->suggestions, static function (string $chip) use ($yes): bool {
            return $chip !== $yes;
        }));
    }

    private function turn(): ?CheckoutTurn
    {
        return isset($this->context['checkout_turn']) && $this->context['checkout_turn'] instanceof CheckoutTurn ? $this->context['checkout_turn'] : null;
    }

    /**
     * Una acción por tipo: si ya hay una del mismo tipo, se queda la última.
     *
     * @param array<string,mixed> $action
     */
    private function action(array $action): void
    {
        foreach ($this->answer->actions as $i => $existing) {
            if ($existing['type'] === $action['type']) {
                $this->answer->actions[$i] = $action;

                return;
            }
        }
        $this->answer->actions[] = $action;
    }

    /**
     * La entrada de una herramienta para el registro: sin datos personales (correo, nombre, teléfono, dirección, el texto libre con
     * que se cuentan) y con los textos recortados.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public static function redact(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (in_array($key, self::PERSONAL, true)) {
                $out[$key] = '***';
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 80, 'UTF-8');
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
