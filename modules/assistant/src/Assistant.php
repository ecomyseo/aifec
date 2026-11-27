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

use Aifec\Kernel\App;

/**
 * Servicio «assistant»: el dependiente de la tienda en conversación.
 *
 * reply() recibe el mensaje de un cliente en una conversación y devuelve
 * ['text', 'cards' => [fichas de producto para pintar], 'actions' => [['type' => 'checkout', 'url'], ['type' => 'cart_changed', 'count']],
 * 'suggestions' => [respuestas rápidas]]. Con IA encendida contesta AiBrain; si la IA falla, se pasa el tope de gasto o de tiempo, o
 * no hay IA, contesta RuleBrain: la tienda funciona igual, solo con menos conversación. Las dos usan las mismas herramientas
 * (servicio «tools»), así que ningún importe sale de otro sitio que del catálogo, el carrito y los envíos.
 *
 * Nunca deja a la persona en un callejón: si la IA no llega a un texto (rondas agotadas, respuesta vacía, tope de 12 segundos, o
 * un texto que copia el resultado de una herramienta) se contesta por reglas con el estado real de la compra (lo que ya se guardó
 * en esta vuelta y lo que falta); si no era la compra y las reglas no entienden la frase, con lo que encuentra el buscador de la
 * tienda de lo que nombra («busco una silla de oficina»); y solo si no hay nada que enseñar, con lo que el asistente sabe hacer.
 *
 * Lo que decide el código y no el modelo: los datos de un pedido de otra persona no se dan (solo en «Mi cuenta»), lo que ya está en
 * el carrito no se vuelve a sumar si el cliente no pide más en ese mensaje (ToolRunner) y el total de varias unidades lo calcula la
 * tienda (QtyQuote, cart_add).
 *
 * El idioma de la conversación es el del cliente (inglés o el de la tienda, ver Lang): todo lo que redacta el motor sale en él.
 *
 * La compra dentro del chat (herramientas checkout_*, ajuste «assistant.checkout»): el pedido NO lo decide ni el modelo ni este
 * servicio por su cuenta. checkout_review deja la conversación esperando confirmación (CheckoutTurn, guardado en el estado de la
 * conversación) y el pedido se hace solo cuando la PERSONA confirma: con el botón «Confirmar pedido» (confirm()) o con un mensaje
 * que es una afirmación clara (Affirmation), comprobado por reglas ANTES de pasar nada a la IA. Cualquier otro mensaje, o un cambio
 * en el carrito o en los datos, anula la espera. Lo que se guarda de la conversación lleva los datos personales enmascarados.
 *
 * Reglas: el mensaje del cliente se recorta a 1.000 caracteres; una conversación admite «assistant.max_messages»
 * mensajes del cliente (30); lo que no supo contestar se marca en el mensaje para que el comerciante vea qué contenido falta.
 */
final class Assistant
{
    /** Lo más largo que se acepta de un mensaje del cliente. */
    public const MAX_MESSAGE = Conversations::MAX_MESSAGE;

    /** Lo más que puede tardar un mensaje en contestarse, en milisegundos: pasado, se contesta con lo que haya o por reglas. */
    public const BUDGET_MS = 8000;

    /** Productos ya enseñados que se recuerdan en la conversación. */
    private const SHOWN = 12;

    /** Lo que se guarda de un fichero o un trozo de código pegado en el chat: el principio, para saber qué era. */
    private const NOISE_KEPT = 120;

    /** Identificadores de producto vistos que se recuerdan en la conversación. */
    private const SEEN = 60;

    /** @var App */
    private $app;

    /** @var Conversations */
    private $conversations;

    /** @var Timer */
    private $timer;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->conversations = new Conversations($app);
        $timer = $app->has('assistant.timer') ? $app->service('assistant.timer') : null;
        $this->timer = $timer instanceof Timer ? $timer : new Timer();
    }

    public function conversations(): Conversations
    {
        return $this->conversations;
    }

    /** El nombre opcional del asistente, siempre como texto y con longitud acotada. */
    public function name(): string
    {
        return mb_substr(trim($this->app->settings()->str('assistant.name')), 0, 60);
    }

    public function aiAvailable(): bool
    {
        return (new AiBrain($this->app, $this->tools(), $this->timer))->available();
    }

    /** Presentación según el modo realmente disponible en la tienda. */
    public function intro(): string
    {
        $previous = $this->formalWording();
        try { return $this->presentation(); }
        finally { if ($previous !== null) { $this->app->i18n()->swapOverrides('es', $previous); } }
    }

    private function formalWording(): ?array
    {
        if ($this->app->i18n()->lang() !== 'es' || $this->app->settings()->str('assistant.address') !== 'formal') { return null; }
        return $this->app->i18n()->swapOverrides('es', require __DIR__ . '/formal.php');
    }

    private function presentation(): string
    {
        $shop = trim($this->app->settings()->str('store.name', 'AIFEC'));
        $ai = $this->aiAvailable();
        $name = $this->name();
        if ($name === '') {
            $text = $ai
                ? t('I am the AI assistant of {shop}. I can help you find products, compare them, quote the shipping and take you to checkout. I only talk about the shop.', ['shop' => $shop])
                : t('I am the shopping assistant of {shop}. I can help you find products, compare them and check delivery options. I only talk about the shop.', ['shop' => $shop]);
        } else {
            $text = $ai
                ? t('I am {assistant}, the AI assistant of {shop}. I can help you find products, compare them, quote the shipping and take you to checkout. I only talk about the shop.', ['assistant' => $name, 'shop' => $shop])
                : t('I am {assistant}, the shopping assistant of {shop}. How can I help you?', ['assistant' => $name, 'shop' => $shop]);
        }
        $extra = trim($this->app->settings()->str('assistant.greeting'));

        return $extra !== '' ? $text . ' ' . $extra : $text;
    }

    /** ¿Se puede hacer el pedido dentro de la conversación? (ajuste «assistant.checkout» y motor de la caja). */
    public function checkoutEnabled(): bool
    {
        return $this->tools()->checkoutEnabled();
    }

    /**
     * Contesta a un mensaje del cliente.
     *
     * @param callable|null $onStatus se llama con lo que se está haciendo («Buscando en la tienda…») mientras corre cada herramienta
     *
     * @return array{text:string,cards:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,suggestions:string[]}
     *
     * @throws \InvalidArgumentException si la conversación no existe
     */
    public function reply(int $conversationId, string $message, ?callable $onStatus = null, string $page = '', ?callable $onFact = null): array
    {
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null) {
            throw new \InvalidArgumentException('Unknown conversation: ' . $conversationId);
        }
        $message = trim(mb_substr(self::clean($message), 0, self::MAX_MESSAGE, 'UTF-8'));
        // Los datos de pago (tarjeta, IBAN, código de seguridad) se quitan aquí, lo primero: de aquí en adelante no los ven ni la
        // conversación guardada, ni las herramientas, ni el registro, ni el modelo.
        $secrets = false;
        $message = Masker::secrets($message, $secrets);
        $state = $this->conversations->state($conversation);
        unset($state['linked_product'], $state['page_product']);
        if ($page !== '' || ProductTalk::asks(RuleBrain::norm($message)) || SensitiveQuestion::matches($message) || ProductQuestion::topic($this->app, $message) !== '') {
            if (preg_match('~https?://[^\s<>]+~iu', $message, $link)) {
                $state['linked_product'] = ProductTalk::linkedProduct($this->app, rtrim($link[0], '.,?!);'));
            } elseif ($page !== '') {
                $state['page_product'] = ProductTalk::linkedProduct($this->app, $page);
            }
        }
        // De un fichero o un trozo de código pegado no se deduce en qué idioma habla el cliente.
        $pageLang = $this->app->lang();
        $previousPage = $state['page_lang'] ?? $conversation['lang'];
        if ($previousPage !== $pageLang) {
            unset($state['lang']);
        }
        $lang = Noise::looks($message) ? null : Lang::detect($message, $pageLang, $this->app->i18n());
        if ($lang === null) {
            $short = str_word_count(RuleBrain::norm($message)) < 5;
            $neutral = !empty($state['flow']['active']) && OrderPrestep::hasData($message);
            $lang = ($short || $neutral) && !empty($state['lang']) ? (string) $state['lang'] : $pageLang;
        }
        $i18n = $this->app->i18n();
        $keep = $i18n->locale();
        if ($lang !== $i18n->lang()) {
            // Las cadenas fuente son inglesas: para contestar en inglés basta con no traducirlas.
            $i18n->setLocale($lang);
        }
        $ai = $this->app->has('ai') ? $this->app->service('ai') : null;
        $before = is_object($ai) && method_exists($ai, 'usageMeter') ? $ai->usageMeter() : null;
        $wording = $this->formalWording();
        try {
            $reply = $this->converse($conversationId, $conversation, $message, $state, $lang, $onStatus, $secrets === true, $onFact, $page);
            if ($secrets || !empty($state['flow']['active']) || OrderPrestep::hasData($message) || Masker::forStorage($message) !== $message) {
                $reply['private_history'] = true;
            }
            return $reply;
        } finally {
            if ($wording !== null) { $i18n->swapOverrides('es', $wording); }
            $i18n->setLocale($keep);
            if ($before !== null) {
                $after = $ai->usageMeter();
                if ($after['calls'] > $before['calls']) {
                    $this->app->db()->run('UPDATE {conversations} SET ai_cost_micro = COALESCE(ai_cost_micro, 0) + ?, ai_unpriced = ai_unpriced + ? WHERE id = ?', [
                        $after['cost'] - $before['cost'], $after['unpriced'] - $before['unpriced'], $conversationId,
                    ]);
                }
            }
        }
    }

    /**
     * El botón «Confirmar pedido» de la tarjeta del resumen (POST /asistente/confirmar). Devuelve null si no hay nada esperando
     * confirmación en esta conversación (no se ha revisado el pedido, ya se hizo, caducó o cambió algo): no se hace nada.
     *
     * @return array{text:string,cards:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,suggestions:string[]}|null
     *
     * @throws \InvalidArgumentException si la conversación no existe
     */
    public function confirm(int $conversationId): ?array
    {
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null) {
            throw new \InvalidArgumentException('Unknown conversation: ' . $conversationId);
        }
        $state = $this->conversations->state($conversation);
        $tools = $this->tools();
        $turn = new CheckoutTurn(isset($state['await']) && is_array($state['await']) ? $state['await'] : null, $this->app->clock()->now());
        if (!$tools->checkoutEnabled() || !$turn->confirm()) {
            return null;
        }
        $lang = isset($state['lang']) && is_string($state['lang']) && $state['lang'] !== '' ? $state['lang'] : $this->app->lang();
        $i18n = $this->app->i18n();
        $keep = $i18n->locale();
        if ($lang !== $i18n->lang()) {
            $i18n->setLocale($lang);
        }
        try {
            $label = t('Confirm order');
            $messageId = $this->conversations->addMessage($conversationId, 'user', $label);
            $context = [
                'cart' => $this->app->has('cart') ? $this->app->service('cart') : null,
                'conversation_id' => $conversationId,
                'checkout_turn' => $turn,
            ];
            $answer = $this->placeOrder($context, $tools, $turn, (string) $conversation['mode']);
            $this->save($conversationId, $messageId, $label, $state, $answer, $turn, [], $lang);

            return $this->finish($answer, $lang, $state)->toArray();
        } finally {
            $i18n->setLocale($keep);
        }
    }

    /**
     * El botón «añadir» de una ficha: la herramienta cart_add con el carrito de esta sesión, sin pasar por la conversación.
     *
     * @return array{text:string,cards:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,suggestions:string[]}
     */
    public function addToCart(int $conversationId, int $productId, int $variantId = 0, int $qty = 1): array
    {
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null) {
            throw new \InvalidArgumentException('Unknown conversation: ' . $conversationId);
        }
        $state = $this->conversations->state($conversation);
        $lang = isset($state['lang']) && is_string($state['lang']) && $state['lang'] !== '' ? $state['lang'] : $this->app->lang();
        $i18n = $this->app->i18n();
        $keep = $i18n->locale();
        if ($lang !== $i18n->lang()) {
            $i18n->setLocale($lang);
        }
        try {
            $tools = $this->tools();
            $answer = new Answer();
            $context = ['cart' => $this->app->has('cart') ? $this->app->service('cart') : null, 'conversation_id' => $conversationId];
            $result = (new ToolRunner($this->app, $tools, $context, $answer, $this->timer))->run('cart_add', ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty]);
            if (isset($result['error'])) {
                $answer->text = (string) $result['error'];
            } elseif (isset($result['need']) && $result['need'] === 'options') {
                // El botón de una ficha con variantes que se coló sin elegir: se pregunta cuál, con botones.
                $answer->text = t('«{name}» comes in several options. Which one do you want?', ['name' => (string) $result['name']]);
            } else {
                $name = (string) (isset($result['name']) ? $result['name'] : '');
                $answer->text = ($name !== '' ? t('Added to your cart: {name}.', ['name' => $name]) : t('Added to your cart.'))
                    . ' ' . t('Total: {total}', ['total' => (string) $result['cart']['total']]);
                // Añadir no es querer pagar: no se piden datos; se ofrece ir a la caja, ver el carrito o seguir mirando.
                $answer->suggestions = Replies::afterAdd();
            }
            PurchaseSuggestions::afterAdd($this->app, $answer, $state);
            if ($answer->cards !== []) {
                $state['cards'] = array_map(static function (array $card): array {
                    return ['id' => (int) $card['id'], 'n' => (string) $card['name'], 'p' => (int) $card['price_cents'], 'v' => (int) $card['has_variants']];
                }, $answer->cards);
            }
            $this->conversations->addMessage($conversationId, 'assistant', $answer->text, $answer->toolLog);
            $changes = ['cart_added' => $answer->cartAdded ? true : (bool) $conversation['cart_added']];
            if (isset($state['await'])) {
                // El carrito ha cambiado: el resumen que se había enseñado ya no vale.
                unset($state['await']);
            }
            unset($state['pick'], $state['chips']);
            if ($answer->pick !== null) {
                $state['pick'] = $answer->pick;
            }
            $changes['state'] = $state;
            $this->conversations->update($conversationId, $changes);

            return $this->finish($answer, $lang, $state)->toArray();
        } finally {
            $i18n->setLocale($keep);
        }
    }

    // ------------------------------------------------------------------ interior

    private function tools(): Tools
    {
        $tools = $this->app->has('tools') ? $this->app->service('tools') : null;

        return $tools instanceof Tools ? $tools : new Tools($this->app);
    }

    /**
     * Lo que queda de reply() una vez decidido el idioma.
     *
     * @param array<string,mixed> $conversation
     * @param array<string,mixed> $state
     *
     * @return array{text:string,cards:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,suggestions:string[]}
     */
    private function converse(int $conversationId, array $conversation, string $message, array $state, string $lang, ?callable $onStatus, bool $secrets = false, ?callable $onFact = null, string $page = ''): array
    {
        if ($message === '') {
            $empty = new Answer();
            $empty->text = t('Write me what you need and I will help you.');

            return $empty->toArray();
        }

        // Una conversación no es infinita: pasado el tope, se manda al cliente a escribir a la tienda.
        $max = $this->app->settings()->int('assistant.max_messages', 0);
        if ($max > 0 && (int) $conversation['messages'] >= $max) {
            $limit = new Answer();
            $limit->text = t('We have been talking for a while and I cannot go on in this conversation. Please write to the shop and a person will help you.');

            return $limit->toArray();
        }

        $turn = new CheckoutTurn(isset($state['await']) && is_array($state['await']) ? $state['await'] : null, $this->app->clock()->now());
        $tools = $this->tools();
        $checkout = $tools->checkoutEnabled();
        $confirmed = false;
        // ¿Estaba el pedido enseñado para confirmar? Si cambia algo después, el resumen se vuelve a enseñar con lo nuevo.
        $hadReview = $turn->armed();
        if ($turn->armed()) {
            // Lo que dice la persona después del resumen: un sí claro confirma; cualquier otra cosa anula la espera.
            if ($checkout && Affirmation::isClear($message)) {
                $confirmed = $turn->confirm();
            } else {
                $turn->disarm();
            }
        }

        // Un fichero o un trozo de código pegado no es una pregunta: no se guarda entero, no se busca nada y no se le pregunta al modelo.
        if (SensitiveQuestion::matches($message)) {
            $messageId = $this->conversations->addMessage($conversationId, 'user', '[sensitive question]');
            $answer = new Answer();
            $answer->actions[] = ['type' => 'private_turn'];
            $answer->text = t('For advice about your health or whether a product is suitable for you, please speak to a doctor or pharmacist.');
            $this->save($conversationId, $messageId, '', $state, $answer, $turn, [], $lang);
            $notice = SensitiveQuestion::catalogueNotice($this->app, $message, (int) ($state['linked_product'] ?? $state['page_product'] ?? 0));
            if ($notice !== '') { $answer->text .= "\n" . $notice; }
            return $this->finish($answer, $lang, $state)->toArray();
        }
        $noise = Noise::looks($message);
        $messageId = $this->conversations->addMessage($conversationId, 'user', Masker::forStorage($noise ? mb_substr($message, 0, self::NOISE_KEPT, 'UTF-8') . '…' : $message));
        if ($noise) {
            $answer = new Answer();
            $answer->text = t('That does not look like a question for the shop. Tell me in a sentence what you are looking for and I will help you.');
            $this->warnSecrets($answer, $secrets);
            $this->save($conversationId, $messageId, '', $state, $answer, $turn, [], $lang);

            return $this->finish($answer, $lang, $state)->toArray();
        }
        // Qué es este mensaje para la necesidad en curso (háblame de, afinar, necesidad vaga, otra cosa…) y cómo queda la necesidad. Con
        // una compra a medias o una pregunta pendiente (código postal, opción) no se toca: lo que escribe es de eso.
        $chip = Replies::clicked($message, $state);
        $busy = !empty($state['flow']['active']) || (isset($state['expect']) && (string) $state['expect'] !== '') || isset($state['pick']) || $turn->armed();
        $kind = $chip !== null ? 'chip' : ($busy ? 'other' : Need::kind($message, $state));
        $lastIds = [];
        foreach (isset($state['cards']) && is_array($state['cards']) ? $state['cards'] : [] as $card) {
            if (is_array($card) && isset($card['id'])) {
                $lastIds[] = (int) $card['id'];
            }
        }
        $need = Need::apply(isset($state['need']) ? $state['need'] : [], $message, $kind, $lastIds);
        $context = [
            'cart' => $this->app->has('cart') ? $this->app->service('cart') : null,
            'conversation_id' => $conversationId,
            'checkout_turn' => $turn,
            'seen' => isset($state['seen']) && is_array($state['seen']) ? $state['seen'] : [],
            'shown' => isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [],
            // Lo que escribió el cliente, entero: las herramientas lo usan para entender «en negro» y para buscar con sus palabras.
            'message' => $message,
            // Lo que no nombra ningún producto («soy hombre», «otra cosa», un botón) no se busca tal cual: lo que afina se busca con la
            // necesidad en curso.
            'on_fact' => $onFact,
            'saved_budget' => ChatPreferences::budget($this->app),
            'own_words' => !in_array($kind, ['refine', 'reset', 'browse', 'chip'], true),
            'refine' => $kind === 'refine' ? Need::query($need) : '',
        ];

        $guided = !$busy && !$confirmed ? ChoiceGuide::answer($this->app, $message, $state) : null;
        if ($guided !== null) {
            $this->save($conversationId, $messageId, '', $state, $guided, $turn, array_column($guided->cards, 'id'), $lang);
            return $this->finish($guided, $lang, $state)->toArray();
        }
        if (!$busy && !$confirmed && $this->app->has('search')) {
            [$word, , $explicit] = RuleBrain::searchQuery(RuleBrain::norm($message));
            $choices = $explicit ? $this->app->service('search')->spellingChoices($word, $this->app->i18n()->lang()) : [];
            if ($choices) {
                $answer = new Answer();
                $answer->text = t('Did you mean one of these catalogue terms?');
                foreach ($choices as $choice) { $answer->suggestions[] = t('Search for {term}', ['term' => $choice]); }
                $this->save($conversationId, $messageId, '', $state, $answer, $turn, [], $lang);
                return $this->finish($answer, $lang, $state)->toArray();
            }
        }
        $orders = AccountOrders::answer($this->app, $message);
        if ($orders !== null) {
            $record = new Answer();
            $record->text = '[account order enquiry]';
            $this->save($conversationId, $messageId, '', $state, $record, $turn, [], $lang);
            return $this->finish($orders, $lang, $state)->toArray();
        }
        $handoff = HumanHandoff::handle($this->app, $conversation, $messageId, $message, $state);
        if ($handoff !== null) {
            $record = clone $handoff;
            $record->text = '[shop team contact]';
            $this->save($conversationId, $messageId, '', $state, $record, $turn, [], $lang);
            return $this->finish($handoff, $lang, $state)->toArray();
        }

        if (!$busy && $this->app->settings()->bool('assistant.replenishment', false) && $this->app->has('orders')
            && PurchaseSuggestions::repeatRequest($message)) {
            $answer = PurchaseSuggestions::repeatOrder($this->app);
            // El historial de compras pertenece a la cuenta, no a la cookie de conversación del navegador.
            $record = new Answer();
            $record->text = '[replenishment request]';
            $this->save($conversationId, $messageId, '', $state, $record, $turn, [], $lang);
            return $this->finish($answer, $lang, $state)->toArray();
        }

        if (!$confirmed && empty($state['pick'])) {
            $facts = ProductMentions::answer($this->app, $message, $state);
            if ($facts === null) { $facts = ProductQuestion::answer($this->app, $message, $state); }
            if ($facts !== null) {
                $this->save($conversationId, $messageId, '', $state, $facts, $turn, array_column($facts->cards, 'id'), $lang);
                return $this->finish($facts, $lang, $state)->toArray();
            }
        }
        if (!$busy && !$confirmed) {
            $guide = MerchantGuide::answer($this->app, $message, $state, $lang, $page);
            if ($guide !== null) {
                $this->save($conversationId, $messageId, $message, $state, $guide, $turn, array_column($guide->cards, 'id'), $lang);
                return $this->finish($guide, $lang, $state)->toArray();
            }
        }

        if (MerchantOffers::requested($message) && $this->app->has('catalog')) {
            $answer = MerchantOffers::answer($this->app);
            $this->save($conversationId, $messageId, $message, $state, $answer, $turn, array_column($answer->cards, 'id'), $lang);
            return $this->finish($answer, $lang, $state)->toArray();
        }

        if ($confirmed) {
            // La persona ha confirmado: el pedido lo hace el motor, sin preguntarle nada a la IA.
            $answer = $this->placeOrder($context, $tools, $turn, (string) $conversation['mode']);
            $this->warnSecrets($answer, $secrets);
            $this->save($conversationId, $messageId, $message, $state, $answer, $turn, [], $lang);

            return $this->finish($answer, $lang, $state)->toArray();
        }

        // Lo que el cliente pide del envío y del pago antes de que le toque elegir se apunta ya, y vale para los dos caminos.
        $n = RuleBrain::norm($message);
        $prefs = isset($state['prefs']) && is_array($state['prefs']) ? $state['prefs'] : [];
        $prefs = Prefs::detect($n, RuleBrain::isQuestion($message, $n), isset($state['expect']) ? (string) $state['expect'] : '') + $prefs;
        $state['prefs'] = $prefs;

        $deadline = $this->timer->ms() + self::BUDGET_MS;
        $answer = new Answer();
        $runner = new ToolRunner($this->app, $tools, $context, $answer, $this->timer);
        $runner->onStatus($onStatus);
        $brain = new AiBrain($this->app, $tools, $this->timer);
        $advice = new Advice($this->app, $tools, $runner, $answer, $state, $context);
        $done = false;
        if ($brain->available()) {
            $answer->aiMs = 0;
            try {
                $rules = new RuleBrain($runner, $answer, $state);
                $account = $this->app->has('customers') && $this->app->service('customers')->current() !== null;
                if ($rules->accountCheckout($message, $account) || $rules->answerShippingAndReturns($message) || $rules->answerDestination($message)) {
                    $answer->aiMs = null;
                    $answer->toolsMs = $runner->toolsMs;
                    $done = true;
                } elseif (isset($state['pick']) && $rules->answerPick($message)) {
                    // Lo que acaba de elegir («en negro», el botón de un color) se añade sin preguntarle nada a la IA.
                    $answer->mode = 'ai';
                    $answer->aiMs = null;
                    $answer->toolsMs = $runner->toolsMs;
                    $done = true;
                } elseif ($rules->answerOrderData($message)) {
                    // Los datos de un pedido («dame la dirección del pedido 2026-000011») solo se ven en «Mi cuenta»: lo dice la tienda,
                    // sin preguntarle a la IA y sin pedir un correo para verificar.
                    $answer->mode = 'ai';
                    $answer->aiMs = null;
                    $answer->toolsMs = $runner->toolsMs;
                    $done = true;
                } elseif (Replies::isCheckoutChip($message)) {
                    // El botón «Ir a la caja»: el enlace de la caja y, si se puede, la opción de hacer el pedido aquí. Sin pedir datos:
                    // eso llega si elige hacerlo en el chat.
                    $rules->run($message);
                    $answer->mode = 'ai';
                    $answer->aiMs = null;
                    $answer->toolsMs = $runner->toolsMs;
                    $done = true;
                } else {
                    $notes = $checkout ? (new OrderPrestep($runner, $answer))->run($message, $prefs) : [];
                    if ($secrets) {
                        $notes[] = 'The customer wrote payment card or bank details. The shop removed them before you saw them and is already telling the customer not to write them here. Do not mention them, do not say you have taken note of them and never ask for them.';
                    }
                    // «Si pido 2, ¿cuánto sale?»: el total lo calcula la tienda antes de preguntar al modelo.
                    $quote = QtyQuote::wanted($message) >= 2 && isset($state['cards']) && is_array($state['cards']) ? QtyQuote::quote($runner, $message, $state['cards']) : null;
                    if ($quote !== null) {
                        $notes[] = $quote['note'];
                    }
                    // Lo que la tienda ya sabe y hace con el mensaje: la ficha del producto por el que pregunta, las categorías ante una
                    // necesidad vaga, la necesidad en curso, los productos de un botón pulsado y el tope de palabras de esta respuesta.
                    $notes = array_merge($notes, $advice->prestep($message, $kind, $need, $chip));
                    $brain->run($conversationId, $runner, $answer, $message, [
                        'lang' => $lang,
                        'notes' => $notes,
                        'shown' => $context['shown'],
                        'deadline' => $deadline,
                        'advice' => in_array($kind, ['new', 'vague', 'refine', 'about', 'chip'], true),
                        'words' => Advice::words($kind),
                        'cats_known' => $advice->knowsCategories(),
                        'categories' => [$advice, 'categories'],
                    ]);
                    $done = $answer->text !== '';
                    if (!$done) {
                        // Para poder medirlo en el registro: por qué el modelo no dejó texto (rounds, timeout, empty, unsafe,
                        // technical) y cuánto buscó. Sin nada del cliente: ni su mensaje ni lo que el modelo llegó a escribir.
                        try {
                            $this->app->log()->info('assistant.no_text', ['ended' => (string) $answer->ended, 'kind' => (string) $kind, 'searches' => (int) $runner->searches, 'hits' => (int) $runner->hits, 'cards' => count($answer->cards)]);
                        } catch (\Throwable $ignored) {
                            // el registro no puede romper una respuesta
                        }
                    }
                    if (!$done && !$answer->wrote && ($runner->hits > 0 || $answer->cards !== [])) {
                        // El modelo buscó y encontró, pero no llegó a escribir (gastó sus rondas buscando, o contestó vacío):
                        // se enseña lo que ÉL encontró, que ya tenía en cuenta lo que el cliente quiere. Dejarlo caer a las
                        // reglas tiraba esas búsquedas y volvía a buscar con las palabras sueltas del mensaje (13/11/2026,
                        // en vivo: «soy hombre» tras «para una boda elegante» → zapatillas al azar).
                        if ($answer->cards === []) {
                            $runner->presentSearch(Answer::MAX_CARDS);
                        }
                        if ($answer->cards !== []) {
                            $answer->text = t('This is what fits best with what you are looking for:');
                            $answer->mode = 'ai';
                            $done = true;
                        }
                    }
                    if (!$done && $answer->wrote) {
                        // El carrito ya cambió y el modelo no llegó a escribir (tope, vacío, error): la respuesta se compone con lo que
                        // hicieron las herramientas. Nunca una disculpa.
                        $this->composeFromTools($answer, $runner, $state, $message);
                        $done = true;
                    }
                    if ($done) {
                        $this->refreshReview($answer, $runner, $turn, $hadReview);
                        self::withTotals($answer, $runner, $quote);
                        $advice->finish($message);
                    }
                }
            } catch (\Throwable $e) {
                // La IA falló (apagada a mitad, tope de gasto, proveedor caído): se contesta por reglas, con lo que ya hubiera.
                try {
                    $this->app->log()->exception($e, 'assistant.ai');
                } catch (\Throwable $ignored) {
                    // Si ni siquiera se puede apuntar, se sigue con las reglas.
                }
            }
        }
        $seen = $runner->seenIds();
        if (!$done) {
            $turnInfo = ['kind' => $kind, 'need' => $need, 'chip' => $chip];
            $attempted = $answer->aiMs !== null;
            $answer = $this->fallback($answer, $runner, $message, $state, $context, $turn, $onStatus, $seen, $turnInfo, $advice);
            $answer->fallbackNotice = $attempted;
        } elseif ($answer->mode === 'ai') {
            // Los botones salen de la respuesta: los que propuso el modelo y pasaron la validación, o los que tocan según lo enseñado.
            $advice->suggest();
        }
        $this->remember($answer, $state, $need, $kind, $advice);
        // El resumen del pedido siempre dice a dónde va, y tras un cambio de dirección, la dirección nueva con su envío.
        OrderReply::withDelivery($answer, $answer->addressChanged && $hadReview);
        $this->warnSecrets($answer, $secrets);
        $this->save($conversationId, $messageId, $message, $state, $answer, $turn, $seen, $lang);

        return $this->finish($answer, $lang, $state)->toArray();
    }

    /**
     * Si el mensaje traía datos de pago (ya quitados), la respuesta empieza diciéndolo con una frase fija de la tienda: no se han
     * guardado y no hay que escribirlos aquí. No se deja en manos del modelo.
     */
    private function warnSecrets(Answer $answer, bool $secrets): void
    {
        if ($secrets) {
            $warning = t('For your safety I have removed the card or bank details from your message: nothing of them has been kept. Never write them in this chat; payment is always made on the secure payment page.');
            $answer->text = trim($warning . "\n\n" . $answer->text);
        }
    }

    /**
     * La respuesta cuando el carrito ya cambió y el modelo no escribió nada: lo que hicieron las herramientas («Añadido a tu carrito:
     * Silla Ergo Pro (Negro). Total: 258,90 €») y, si hay un pedido en marcha, lo siguiente que falta de él.
     *
     * @param array<string,mixed> $state
     */
    private function composeFromTools(Answer $answer, ToolRunner $runner, array $state, string $message = ''): void
    {
        $lines = [];
        $total = '';
        foreach ($runner->writeLog() as $entry) {
            if ($entry['name'] === 'cart_add') {
                $label = (string) $entry['label'];
                $lines[] = (int) $entry['qty'] > 1
                    ? t('Added to your cart: {qty} × {name}.', ['qty' => (int) $entry['qty'], 'name' => $label])
                    : t('Added to your cart: {name}.', ['name' => $label]);
            } else {
                $lines[] = t('I have updated your cart.');
            }
            if ((string) $entry['total'] !== '') {
                $total = (string) $entry['total'];
            }
        }
        if ($total !== '') {
            $lines[] = t('Total: {total}', ['total' => $total]);
        }
        $answer->ended = 'text';
        $buying = ($answer->usedCheckout() || !empty($state['flow']['active'])) && $this->tools()->checkoutEnabled();
        if ($buying) {
            $dialog = (new CheckoutDialog($runner, $answer, $state))->withMessage(RuleBrain::norm($message));
            if ($dialog->continueOrder($lines, self::chosenFrom($answer))) {
                return;
            }
        }
        $answer->text = implode("\n", $lines);
        $answer->suggestions = array_merge($answer->suggestions, $answer->cartAdded ? Replies::afterAdd() : [t('Show my cart'), t('Go to checkout')]);
    }

    /**
     * Lo que pasa al siguiente mensaje del consejo: la necesidad en curso (con los productos que se enseñaron para ella, y sin datos
     * personales), las categorías de la tienda ya leídas y los botones que llevan a productos.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $need
     */
    private function remember(Answer $answer, array $state, array $need, string $kind, Advice $advice): void
    {
        if (in_array($kind, ['new', 'vague', 'refine', 'chip'], true)) {
            $ids = [];
            foreach ($answer->cards as $card) {
                $ids[] = (int) $card['id'];
            }
            $need = Need::showed($need, $ids);
        }
        foreach (['q', 's'] as $key) {
            if (isset($need[$key])) {
                $need[$key] = Masker::forStorage((string) $need[$key]);
            }
        }
        $answer->remember['need'] = $need;
        $cats = $advice->stateCats();
        if ($cats === null && isset($state['cats']) && is_array($state['cats'])) {
            $cats = $state['cats'];
        }
        if ($cats !== null) {
            $answer->remember['cats'] = $cats;
        }
        if ($answer->chipMeta !== []) {
            $answer->remember['chips'] = array_intersect_key($answer->chipMeta, array_flip($answer->suggestions));
        }
    }

    /**
     * Lo que cuestan varias unidades lo dice el código, no el modelo: por cada «añadir» de más de una unidad («2 × Silla Básica Home
     * a 89,00 € son 178,00 €») y por la pregunta de cuánto saldrían, si el texto no trae ya ese total.
     *
     * @param array{line:string,total:string,note:string,product_id:int}|null $quote
     */
    private static function withTotals(Answer $answer, ToolRunner $runner, ?array $quote): void
    {
        $lines = [];
        foreach ($runner->writeLog() as $entry) {
            if ($entry['name'] === 'cart_add' && (int) $entry['qty'] > 1 && isset($entry['sum'], $entry['unit']) && (string) $entry['sum'] !== '' && strpos($answer->text, (string) $entry['sum']) === false) {
                $lines[] = t('{qty} × {name} at {unit} make {total}.', ['qty' => (int) $entry['qty'], 'name' => (string) $entry['label'], 'unit' => (string) $entry['unit'], 'total' => (string) $entry['sum']]);
            }
        }
        if ($quote !== null && strpos($answer->text, $quote['total']) === false) {
            $lines[] = $quote['line'];
        }
        foreach (array_unique($lines) as $line) {
            if (strpos($answer->text, $line) === false) {
                $answer->text = trim($answer->text . "\n" . $line);
            }
        }
    }

    /**
     * Si el pedido ya estaba enseñado para confirmar y en esta respuesta cambió algo (la dirección, el envío, el pago), el resumen se
     * vuelve a enseñar con lo nuevo: sin él, el «sí» de la persona ya no confirmaría nada y no vería a dónde va el pedido.
     */
    private function refreshReview(Answer $answer, ToolRunner $runner, CheckoutTurn $turn, bool $hadReview): void
    {
        if (!$hadReview || $turn->armed() || !$this->tools()->checkoutEnabled()) {
            return;
        }
        $edited = false;
        foreach ($answer->toolLog as $entry) {
            if (!empty($entry['ok']) && in_array($entry['name'], ['checkout_set_customer', 'checkout_set_shipping', 'checkout_set_payment'], true)) {
                $edited = true;
            }
        }
        if (!$edited) {
            return;
        }
        $review = $runner->run('checkout_review', []);
        if ($turn->armed() && isset($review['ready']) && $review['ready'] === true && $answer->suggestions === []) {
            $answer->suggestions[] = t('Yes, I confirm');
            $answer->suggestions[] = t('Cancel');
        }
    }

    /**
     * Lo que ya eligió la persona, mirando lo que se hizo en esta respuesta: ship y pay a true.
     *
     * @return array<string,bool>
     */
    private static function chosenFrom(Answer $answer): array
    {
        $chosen = ['ship' => false, 'pay' => false];
        foreach ($answer->toolLog as $entry) {
            if (!empty($entry['ok']) && $entry['name'] === 'checkout_set_shipping') {
                $chosen['ship'] = true;
            } elseif (!empty($entry['ok']) && $entry['name'] === 'checkout_set_payment') {
                $chosen['pay'] = true;
            }
        }

        return $chosen;
    }

    /**
     * Contesta por reglas cuando la IA no ha llegado a un texto: con el resumen del pedido si la IA lo dejó revisado; con el estado
     * real de la compra (lo que ya se guardó y lo que falta) si se estaba comprando; y, si no, con lo que el asistente sabe hacer.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $context
     * @param int[]               $seen   se completa con los productos que salgan en la respuesta por reglas
     * @param array<string,mixed> $info   kind, need y chip del mensaje (Need, Replies)
     * @param Advice              $advice el consejo de la IA; sale el de las reglas (con las categorías que ya se hubieran leído)
     */
    private function fallback(Answer $old, ToolRunner $oldRunner, string $message, array $state, array $context, CheckoutTurn $turn, ?callable $onStatus, array &$seen, array $info, Advice &$advice): Answer
    {
        $cats = $advice->stateCats();
        if ($cats !== null) {
            // Las categorías que ya leyó el camino de la IA no se vuelven a pedir.
            $state['cats'] = $cats;
        }
        if ($turn->armed() && is_array($turn->card) && !$old->wrote) {
            // La IA dejó el pedido revisado y no escribió nada: el resumen es lo que hay que decirle a la persona.
            $old->text = OrderReply::reviewText($turn->card);
            $old->suggestions = [t('Yes, I confirm'), t('Cancel')];
            $old->ended = 'text';

            return $old;
        }
        // Lo que la IA hubiera dejado esperando confirmación no llegó a enseñárselo a la persona: no vale.
        $turn->disarm();
        $turn->card = null;
        $fresh = new Answer();
        $fresh->toolLog = $old->toolLog;
        $fresh->aiMs = $old->aiMs;
        $fresh->rounds = $old->rounds;
        $fresh->ended = $old->ended;
        $fresh->prefsUsed = $old->prefsUsed;
        $fresh->wrote = $old->wrote;
        $fresh->emptySearch = $old->emptySearch;
        $fresh->cartAdded = $old->cartAdded;
        $fresh->addressChanged = $old->addressChanged;
        $fresh->actions = array_values(array_filter($old->actions, static function (array $action): bool {
            return isset($action['type']) && $action['type'] === 'cart_changed';
        }));
        $context['seen'] = $seen;
        // Lo que la IA ya añadió al carrito en esta respuesta no se vuelve a añadir por reglas.
        $context['added'] = $oldRunner->addedLines();
        $runner = new ToolRunner($this->app, $this->tools(), $context, $fresh, $this->timer);
        $runner->onStatus($onStatus);
        $chosen = self::chosenFrom($old);
        $buying = $old->usedCheckout() && $this->tools()->checkoutEnabled();
        $handled = false;
        if ($buying) {
            $lead = [];
            foreach ($old->toolLog as $entry) {
                if (!empty($entry['ok']) && $entry['name'] === 'checkout_set_customer') {
                    $lead = [t('Thanks, I have saved your details.')];
                    break;
                }
            }
            $dialog = (new CheckoutDialog($runner, $fresh, $state))->withMessage(RuleBrain::norm($message));
            $handled = $dialog->continueOrder($lead, $chosen);
            if ($handled) {
                $fresh->mode = 'ai';
            }
        }
        $advice = new Advice($this->app, $this->tools(), $runner, $fresh, $state, $context);
        if (!$handled) {
            $rules = new RuleBrain($runner, $fresh, $state);
            $rules->withAdvice($advice, (string) $info['kind'], is_array($info['need']) ? $info['need'] : [], is_array($info['chip']) ? $info['chip'] : null);
            $rules->run($message);
            // La IA lo intentó (error, vacío, fuera de tiempo) y las reglas no entienden la frase: si habla de productos se enseña lo que
            // encuentra el buscador de la tienda; solo si no hay nada que enseñar se dice que no se ha podido contestar, con los botones
            // de lo que sí se sabe hacer (los que puso RuleBrain al no entender).
            if ($rules->gaveUp() && $old->aiMs !== null && !$rules->showFromPhrase($message)) {
                $fresh->text = t('I could not answer that. You can ask me about a product, the shipping to your postcode, your cart or an order, or write to the shop.');
            }
        }
        $fresh->toolsMs = $old->toolsMs + $runner->toolsMs;
        $seen = array_values(array_unique(array_merge($seen, $runner->seenIds())));

        return $fresh;
    }

    /**
     * Hace el pedido con la confirmación ya recogida (botón o sí claro): la herramienta checkout_place y lo que se le dice a la
     * persona después. La IA no interviene.
     *
     * @param array<string,mixed> $context
     */
    private function placeOrder(array $context, Tools $tools, CheckoutTurn $turn, string $mode): Answer
    {
        $answer = new Answer();
        $answer->mode = $mode === 'ai' ? 'ai' : 'rules';
        $result = (new ToolRunner($this->app, $tools, $context, $answer, $this->timer))->run('checkout_place', []);
        OrderReply::placed($answer, $result, $turn);
        if (is_array($turn->placed) && !isset($result['error'])) {
            $this->conversations->recordOrder((int) $context['conversation_id'], (int) $turn->placed['id']);
        } elseif (isset($result['failed_order_id'])) {
            $this->conversations->unmarkOrder((int) $context['conversation_id'], (int) $result['failed_order_id']);
        }

        return $answer;
    }

    /**
     * Antes de entregar la respuesta: si la conversación va en otro idioma que la página, las cadenas del JavaScript de la ventana
     * (botones, tarjetas) se mandan ya traducidas en una acción «labels»; si vuelve al de la página, se quitan.
     *
     * @param array<string,mixed> $state el estado de ANTES de esta respuesta
     */
    private function finish(Answer $answer, string $lang, array $state): Answer
    {
        if ($answer->cards !== [] && $this->app->has('catalog')) {
            $products = $this->app->service('catalog')->cards(array_column($answer->cards, 'id'));
            $byId = array_column($products, null, 'id');
            foreach ($answer->cards as &$card) {
                $product = $byId[$card['id']] ?? [];
                if (!empty($card['url']) && ($card['status'] ?? '') === 'out_of_stock') {
                    $target = $this->app->hooks()->apply('assistant.stock_signup_anchor', '', (int) $card['id']);
                    if (is_string($target) && preg_match('/^[a-z][a-z0-9_-]{0,80}$/iD', $target)) {
                        $card['stock_signup'] = $card['url'] . '#' . $target;
                        $card['stock_signup_label'] = t('Notify me when available');
                    }
                }
                $card['delivery'] = \Aifec\Modules\Catalog\Front\Support::delivery($product['availability']['delivery_days'] ?? null);
                $price = $product['price'] ?? [];
                $compare = (int) ($price['compare'] ?? 0);
                if ($compare > $card['price_cents'] && !empty($card['compare_price'])) {
                    $card['discount_percent'] = (int) round(100 * ($compare - $card['price_cents']) / $compare);
                }
                if ($this->app->has('reviews') && !empty($product['rating']['count'])) {
                    $card['rating'] = $product['rating'];
                }
            }
            unset($card);
        }
        $base = $this->app->lang();
        $before = isset($state['lang']) && is_string($state['lang']) && $state['lang'] !== '' ? $state['lang'] : $base;
        if ($lang !== $base || $before !== $base) {
            $labels = [];
            if ($lang !== $base) {
                foreach (Lang::LABELS as $name => $source) {
                    $labels[$name] = t($source);
                }
            }
            array_unshift($answer->actions, ['type' => 'labels', 'labels' => $labels]);
        }

        return $answer;
    }

    /**
     * Guarda la respuesta y deja la conversación al día: modo, qué espera el asistente, la compra empezada y hasta dónde llegó.
     * Lo que se guarda lleva los datos personales enmascarados; lo que ve la persona, no.
     *
     * @param array<string,mixed> $state
     * @param int[]               $seen  los productos que han salido en resultados de herramienta
     */
    private function save(int $conversationId, int $messageId, string $message, array $state, Answer $answer, CheckoutTurn $turn, array $seen, string $lang): void
    {
        PurchaseSuggestions::afterAdd($this->app, $answer, $state);
        $publicText = SensitiveQuestion::withoutClaims($answer->text);
        if ($publicText === '' && trim($answer->text) !== '') {
            $publicText = t('I can tell you what the product page lists. For health advice, consult a healthcare professional.');
        }
        $answer->text = $publicText;
        $this->conversations->addMessage($conversationId, 'assistant', Masker::text($answer->text, $turn->personal), $answer->toolLog, [
            'ai_ms' => $answer->aiMs,
            'tools_ms' => $answer->toolsMs,
            'rounds' => $answer->rounds,
        ]);
        SearchEvidence::capture($this->app, $messageId, $message, $answer->toolLog, $lang);
        if ($answer->emptySearch && $answer->cards === [] && $message !== '') {
            $this->app->db()->update('conversation_messages', [
                'empty_search' => 1, 'phrase' => Conversations::phrase(Masker::forStorage($message)),
            ], ['id' => $messageId, 'role' => 'user']);
        }
        if ($answer->unanswered) {
            $this->conversations->markUnanswered($messageId, $conversationId, $message);
        }

        $next = ['page_lang' => $this->app->lang()];
        if ($answer->expect !== '') {
            $next['expect'] = $answer->expect;
        }
        $next += $answer->remember;
        // La necesidad en curso y las categorías de la tienda siguen de un mensaje al siguiente aunque esta respuesta no las tocara.
        foreach (['need', 'cats', 'cross_sell_shown', 'choice_guide'] as $key) {
            if (!array_key_exists($key, $answer->remember) && isset($state[$key]) && is_array($state[$key])) {
                $next[$key] = $state[$key];
            }
        }
        if (isset($next['need']) && $next['need'] === []) {
            unset($next['need']);
        }
        // La compra empezada en el chat y el pedido que espera confirmación pasan de un mensaje al siguiente.
        $flow = isset($state['flow']) && is_array($state['flow']) ? $state['flow'] : [];
        $flow = array_merge($flow, $answer->flow);
        if (!isset($answer->flow['active'])) {
            // Lo que la IA hizo con las herramientas de la caja también cuenta: el envío y el pago ya elegidos no se vuelven a preguntar.
            foreach ($answer->toolLog as $entry) {
                if (empty($entry['ok'])) {
                    continue;
                }
                if ($entry['name'] === 'checkout_set_shipping') {
                    $flow['ship'] = 1;
                } elseif ($entry['name'] === 'checkout_set_payment') {
                    $flow['pay'] = 1;
                }
                if (in_array($entry['name'], ['checkout_set_customer', 'checkout_set_shipping', 'checkout_set_payment', 'checkout_review'], true)) {
                    $flow['active'] = 1;
                }
            }
        }
        if (!empty($flow['active'])) {
            $next['flow'] = $flow;
        }
        $await = $turn->await();
        if ($await !== null) {
            $next['await'] = $await;
        }
        // Las fichas que se acaban de enseñar sirven para «añade el primero»; si no hay nuevas, siguen las de antes.
        $cards = [];
        foreach ($answer->cards as $card) {
            $cards[] = ['id' => (int) $card['id'], 'n' => mb_substr((string) $card['name'], 0, 80, 'UTF-8'), 'v' => !empty($card['has_variants']) ? 1 : 0, 'p' => (int) $card['price_cents']];
        }
        if ($cards !== []) {
            $next['cards'] = $cards;
        } elseif (isset($state['cards']) && is_array($state['cards'])) {
            $next['cards'] = $state['cards'];
        }
        // Lo que ya se enseñó en la conversación (para no decir «no tenemos» de algo que ya se enseñó) y lo que ha salido de las
        // herramientas (la lista de la que puede elegir fichas la herramienta show_products).
        $shown = isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [];
        foreach ($cards as $card) {
            $shown = array_values(array_filter($shown, static function ($row) use ($card): bool {
                return !is_array($row) || !isset($row['id']) || (int) $row['id'] !== $card['id'];
            }));
            $shown[] = ['id' => $card['id'], 'n' => $card['n']];
        }
        if ($shown !== []) {
            $next['shown'] = array_slice($shown, -self::SHOWN);
        }
        $known = array_merge(isset($state['seen']) && is_array($state['seen']) ? $state['seen'] : [], $seen);
        $known = array_values(array_unique(array_map('intval', $known)));
        if ($known !== []) {
            $next['seen'] = array_slice($known, -self::SEEN);
        }
        // Lo que pidió del envío y del pago: se queda hasta que se aplica.
        $prefs = isset($state['prefs']) && is_array($state['prefs']) ? $state['prefs'] : [];
        $used = $answer->prefsUsed;
        foreach ($answer->toolLog as $entry) {
            if (!empty($entry['ok']) && $entry['name'] === 'checkout_set_shipping') {
                $used[] = 'ship';
            } elseif (!empty($entry['ok']) && $entry['name'] === 'checkout_set_payment') {
                $used[] = 'pay';
            }
        }
        foreach ($used as $key) {
            unset($prefs[$key]);
        }
        if ($prefs !== []) {
            $next['prefs'] = $prefs;
        }
        if ($answer->pick !== null) {
            $next['pick'] = $answer->pick;
        }
        if ($lang !== $this->app->lang()) {
            $next['lang'] = $lang;
        }

        $changes = ['mode' => $answer->mode, 'state' => $next];
        if ($answer->cartAdded) {
            $changes['cart_added'] = true;
        }
        if ($answer->checkout) {
            $changes['checkout'] = true;
        }
        $this->conversations->update($conversationId, $changes);
    }

    /** Quita los caracteres de control (menos el salto de línea y el tabulador) de lo que escribe el cliente. */
    private static function clean(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($text, 'UTF-8'));

        return $clean === null ? '' : $clean;
    }
}
