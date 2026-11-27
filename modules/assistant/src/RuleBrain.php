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

use Aifec\Assistant\CustomerData;

/**
 * El asistente sin IA: entiende un puñado de intenciones por reglas (saludar, buscar, enviar a un código postal,
 * consultar un pedido, devoluciones, pago, contacto, carrito, añadir lo que se acaba de enseñar y pagar) y contesta
 * siempre con lo que devuelven las herramientas. Lo que no entiende lo dice, ofrece opciones y lo marca para el panel.
 *
 * Reconoce castellano e inglés. Compara sobre el texto normalizado (minúsculas, sin tildes, sin signos); los correos se
 * sacan antes con CustomerData para que ni su «@» ni su dominio se confundan con una intención.
 */
final class RuleBrain
{
    private const RE_GREETING_WORDS = '/\b(?:hola|holaa+|buenas|buenos|dias|tardes|noches|hey|hello|hi|good|morning|afternoon|evening|que|tal|saludos|buen|dia|a|todos|amigos|asistente|bot)\b/';

    private const RE_GREETING_START = '/\b(?:hola|holaa+|buenas|buenos dias|buen dia|hey|hello|hi|good morning|good afternoon|good evening|que tal|saludos)\b/';

    private const RE_THANKS = '/\b(?:gracias|thanks|thank you)\b/';

    private const RE_BYE = '/\b(?:adios|hasta luego|hasta pronto|chao|bye|goodbye|nada mas)\b/';

    private const RE_ORDER = '/\b(?:pedido|pedidos|order|orders|seguimiento|tracking|rastrear|mi compra)\b/';

    private const RE_SHIPPING = '/\b(?:envio|envios|enviar|enviais|enviamos|entrega|entregar|entregais|transporte|plazo|tarda|tardan|shipping|delivery|deliver|ship)\b/';

    /** «¿Me dais factura?», «¿cómo recibo la factura?»: cómo llega la factura (y no la empresa a la que se factura, que es la caja). */
    private const RE_INVOICE_INFO = '/\b(?:(?:dais|dan|das|haceis|hacen|emitis|emiten|enviais|envian|mandais|mandan|recib\w*|tendr\w*|llega\w*|descarg\w*|consegu\w*|pued\w+ (?:tener|pedir|descargar)|hay|teneis|tienen) (?:la |una |mi )?factura|factura\b.{0,30}\b(?:como|cuando|donde|por (?:correo|mail|email))|como (?:me )?(?:llega|recibo|consigo) (?:la )?factura|do you (?:issue|send|provide) (?:an |the )?invoices?|(?:get|receive|download) (?:an |the |my )?invoice|where is my invoice)/';
    private const RE_PAYMENT_INFO = '/\b(?:formas? de pago|metodos? de pago|medios? de pago|opciones de pago|que puedo pagar con|con que (?:puedo )?pag\w+|pag(?:ar|o|os) (?:con|por|en)|aceptais|aceptan|payment methods?|payment options?|how (?:can|do) i pay|do you accept|pag\w* (?:al|cuando) (?:recib|lleg|entreg)\w*|pago (?:a|en) la entrega|pagar en (?:casa|mano|efectivo)|pay (?:on|at) delivery|cash on delivery|paypal|bizum|transferencia|contra ?reembolso)\b/';

    private const RE_CHECKOUT = '/\b(?:pagar|finalizar|checkout|tramitar|tramito|terminar (?:la )?compra|comprar ahora|ir a (?:la )?caja|pay|proceed to checkout|complete (?:my )?(?:order|purchase))\b/';

    /** Ir a la caja de la web (no hacer el pedido aquí). */
    private const RE_CHECKOUT_PAGE = '/\b(?:ir a (?:la )?caja|a la caja|checkout|go to checkout|proceed to checkout)\b/';

    /** Hacer el pedido en el chat: frases que solo pueden ser eso (antes de «pedido», que también es consultar uno). */
    private const RE_PLACE = '/\b(?:(?:hacer|haz|hazme|realizar|realiza|tramitar|tramita|completar|completa) (?:el |mi |un |este |ese )?pedido|pedirlo|pidelo|pedir esto|quiero pedir|comprarlo|comprar lo|quiero comprar(?:lo)?|lo compro|me lo llevo|finalizar (?:la |el |mi )?(?:compra|pedido)|terminar (?:la |mi )?compra|place (?:the |my |an )?order|order it|buy it|buy now|i want to buy|checkout here|complete (?:my )?(?:order|purchase))\b/';

    private const RE_RETURNS = '/\b(?:devol\w*|reembols\w*|return|returns|refund|refunds|garantia)\b/';

    private const RE_CONTACT = '/\b(?:contacto|contactar|contactaros|contactarnos|telefono|llamar|llamaros|whatsapp|correo|mail|email|escribir|escribiros|hablar con|persona|humano|agente|asesor|atencion al cliente|contact|phone|call|human|agent|support)\b/';

    private const RE_HOURS = '/\b(?:horario|horarios|abiertos?|abris|abren|cierran|cerrais|hours|opening|open)\b/';

    private const RE_CART = '/\b(?:carrito|cesta|bolsa|cart|basket)\b/';

    private const RE_CART_VIEW = '/\b(?:ver|mira|muestra\w*|ensena\w*|que tengo|contenido|lo que llevo|show|view|see|what)\b/';

    private const RE_ADD = '/\b(?:anade|anadir|anademe|anadelo|anadela|agrega|agregar|agregalo|agregala|mete|metelo|metela|ponlo|ponla|ponme|pon|add|put)\b/';

    /** Quien busca otra cosa («busco otro en azul») no pregunta por una opción del producto que tiene delante. */
    private const RE_OTHER = '/\b(?:busco|buscar|busca|buscame|ensename|muestrame|otro|otra|otros|otras|search|find|looking|another|other)\b/';

    private const RE_COMPARE = '/\b(?:compara\w*|comparativa|diferencias?|cual es mejor|which is better|compare|comparison|difference between)\b/';

    private const RE_QUESTION_START ='/^(?:que|como|cuando|donde|quien|por que|cual|cuales|cuanto|what|how|when|where|who|why|which)\b/';

    /** @var ToolRunner */
    private $runner;

    /** @var Answer */
    private $answer;

    /** @var array<string,mixed> */
    private $state;

    /** @var CheckoutDialog */
    private $dialog;

    /** @var bool el mensaje de esta vuelta lo ha atendido la compra en el chat */
    private $inOrder = false;

    /** @var bool no ha sabido qué decir: solo ha podido recordar lo que sabe hacer */
    private $gaveUp = false;

    /** @var Advice|null el consejo de este mensaje (háblame de, afinar, necesidad vaga, botones con productos) */
    private $advice;

    /** @var string qué es el mensaje para la necesidad en curso (Need::kind) */
    private $kind = 'other';

    /** @var array<string,mixed> la necesidad en curso, ya con este mensaje */
    private $need = [];

    /** @var array<string,mixed>|null el botón pulsado (Replies::clicked) */
    private $chip;

    /**
     * @param array<string,mixed> $state lo que se recordó de la conversación: expect, cards, order_number, flow
     */
    public function __construct(ToolRunner $runner, Answer $answer, array $state)
    {
        $this->runner = $runner;
        $this->answer = $answer;
        $this->state = $state;
        $this->dialog = new CheckoutDialog($runner, $answer, $state);
    }

    /**
     * Lo que el consejo hace con este mensaje: «háblame de X», un mensaje que afina, una necesidad vaga, un botón pulsado.
     *
     * @param array<string,mixed>      $need
     * @param array<string,mixed>|null $chip
     */
    public function withAdvice(Advice $advice, string $kind, array $need, ?array $chip): self
    {
        $this->advice = $advice;
        $this->kind = $kind;
        $this->need = $need;
        $this->chip = $chip;

        return $this;
    }

    /** ¿Pide añadir algo al carrito en este mensaje? («añádelo», «add it»). */
    public static function asksToAdd(string $message): bool
    {
        return preg_match(self::RE_ADD, self::norm($message)) === 1;
    }

    /** ¿Dice que quiere comprar, pagar o terminar? («quiero pagar», «lo compro», «finalizar la compra»). */
    public static function wantsToBuy(string $message): bool
    {
        $n = self::norm($message);

        return preg_match(self::RE_PLACE, $n) === 1 || preg_match(self::RE_CHECKOUT, $n) === 1;
    }

    /** ¿Es una pregunta? (lleva «?» o empieza por una palabra interrogativa). */
    public static function isQuestion(string $message, string $n): bool
    {
        return strpos($message, '?') !== false || strpos($message, '¿') !== false || preg_match(self::RE_QUESTION_START, $n) === 1;
    }

    /** Texto normalizado para comparar: minúsculas, sin tildes y con los signos convertidos en espacios. */
    public static function norm(string $text): string
    {
        $s = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
        $s = preg_replace('/[^a-z0-9@€#\-\s.,]/u', ' ', $s);
        // Un punto o una coma solo valen dentro de un número («49,90»).
        $s = preg_replace('/(?<!\d)[.,]|[.,](?!\d)/', ' ', $s === null ? '' : $s);
        $s = preg_replace('/\s+/', ' ', $s === null ? '' : $s);

        return trim($s === null ? '' : $s);
    }

    /** ¿Se rindió con el último mensaje (no lo entendió y solo dijo lo que sabe hacer)? */
    public function gaveUp(): bool
    {
        return $this->gaveUp;
    }

    /**
     * Atiende el mensaje solo si es la respuesta a «¿qué opción quieres?» (el botón o el texto: «en negro»). Lo usa el modo con IA,
     * que no tiene que preguntarle nada al modelo para añadir lo que el cliente acaba de elegir. false: no era eso.
     */
    public function answerPick(string $message): bool
    {
        $pick = isset($this->state['pick']) && is_array($this->state['pick']) ? $this->state['pick'] : null;

        return $pick !== null && $this->choose($pick, $message);
    }

    public function run(string $message): void
    {
        $this->answer->mode = 'rules';
        $this->inOrder = false;
        $this->gaveUp = false;
        $this->route($message);
        // Un pedido a medias y un mensaje que no era de la compra: se ofrece volver a él.
        if (!$this->inOrder) {
            $this->dialog->nudge();
        }
    }

    /** Las decisiones de la persona sobre su dirección se resuelven en la tienda. */
    public function accountCheckout(string $message, bool $offer = false): bool
    {
        $n = self::norm($message);
        if ($this->dialog->answerAddress($n) || $this->dialog->cancelled($n)) {
            $this->inOrder = true;
            return true;
        }
        return $offer && !$this->dialog->active() && $this->dialog->available()
            && preg_match(self::RE_PLACE, $n) === 1 && self::catalogueRequest($n) === null
            && $this->dialog->start();
    }

    public function answerShippingAndReturns(string $message): bool
    {
        $n = self::norm($message);
        $shipping = '(?:envios?|entregas?|portes?|shipping|delivery)';
        $returns = '(?:devoluci\w*|devolver\w*|returns?|refunds?)';
        if (!preg_match('/\b(?:' . $shipping . ' (?:y|e|and) (?:las? |los? )?' . $returns . '|' . $returns . ' (?:y|and) (?:el |los? )?' . $shipping . ')\b/', $n)) {
            return false;
        }
        $this->returns();
        $policy = $this->answer->text;
        if (!$this->answerDestination($message)) {
            $postal = $this->postcodeOf(CustomerData::extract($message), $n);
            if ($postal !== '') {
                $this->shipping($postal);
            } else {
                $this->say(t('What is your postcode? I will quote the shipping for you.'));
                $this->answer->expect = 'postcode';
            }
        }
        $this->answer->text .= "\n\n" . $policy;
        return true;
    }

    /** La misma cotización con IA y por reglas. El destino solicitado nunca se escribe en el pedido. */
    public function answerDestination(string $message): bool
    {
        $normal = self::norm($message);
        if (preg_match('/\b(?:hacer efecto|surtir efecto|take effect|start working)\b/', $normal)
            && preg_match('/\b(?:tarda|tardan|tiempo|cuanto|long|when|time)\b/', $normal)) {
            $this->say(t('Which product do you mean? I can check what its product page says about using it.'));
            return true;
        }
        $waiting = ($this->state['expect'] ?? '') === 'postcode';
        $destination = QuoteDestination::named($normal, $waiting, app()->i18n()->lang());
        if ($destination === null && $waiting && isset($this->state['quote_destination'])
            && preg_match('/^[A-Z0-9 -]{3,12}$/iD', trim($message)) && preg_match('/[0-9]/', $message)) {
            $destination = $this->state['quote_destination'];
        }
        if ($destination === null) {
            $saved = QuoteDestination::savedFor($normal);
            if ($saved === null) { return false; }
            $this->shipping($saved['postcode'], $saved['country']);
            $this->answer->text = str_replace($saved['postcode'], t('your saved delivery address'), $this->answer->text)
                . "\n" . t('To check another address, tell me its country and postcode.');
            $this->answer->actions[] = ['type' => 'private_turn'];
            return true;
        }
        $postcode = QuoteDestination::postcode($message, $destination);
        if ($postcode === '') {
            $this->say(t('What is the destination postcode for {place}? I will use it for this shipping quote.', ['place' => $destination['name']]));
            $this->answer->expect = 'postcode';
            $this->answer->remember['quote_destination'] = $destination;
        } else {
            $this->shipping($postcode, $destination['country']);
        }
        return true;
    }

    private function route(string $message): void
    {
        if ($this->answerShippingAndReturns($message)) { return; }
        if ($this->answerDestination($message)) {
            return;
        }
        $data = CustomerData::extract($message);
        $email = isset($data['email']) ? (string) $data['email'] : '';
        $n = self::norm($email !== '' ? str_ireplace($email, ' ', $message) : $message);
        $expect = isset($this->state['expect']) ? (string) $this->state['expect'] : '';
        $number = $this->orderNumber($n);
        $words = $n === '' ? [] : explode(' ', $n);

        if ($n === '' && $email === '') {
            $this->say(t('Write me what you need and I will help you.'));
            $this->chips(['returns', 'payment', 'order']);

            return;
        }

        if (preg_match('/\b(?:no me (?:estas )?ayuda(?:s|ndo)|no me sirve tu respuesta|not helping|this is frustrating)\b/', $n) === 1) {
            $this->contact();
            $this->answer->text = t('I am sorry this has been frustrating.') . ' ' . $this->answer->text;
            return;
        }
        if (preg_match('/\b(?:quien (?:gano|ganara)|who (?:won|will win)|resultado del partido|weather forecast|pronostico del tiempo)\b/', $n) === 1) {
            $this->say(t('I can help you with this shop, its products, shipping and orders.'));
            $this->chips(['returns', 'payment', 'order']);
            return;
        }
        // Un pedido empezado en el chat: dejarlo y volver a él se atienden antes que nada.
        if ($this->accountCheckout($message)) {
            $this->inOrder = true;

            return;
        }
        if ($this->dialog->resumes($n)) {
            $this->inOrder = true;

            return;
        }

        // Se le acaba de preguntar qué opción quiere de un producto («¿en qué color?»): lo que dice puede ser la respuesta.
        $pick = isset($this->state['pick']) && is_array($this->state['pick']) ? $this->state['pick'] : null;
        if ($pick !== null && $this->choose($pick, $message)) {
            return;
        }

        // Un botón de los que se ofrecieron con productos detrás («Para hombre», «Hasta 60,00 €», «Parecidos», «Ver tallas»).
        if ($this->advice !== null && $this->chip !== null && $this->advice->rulesChip($this->chip)) {
            return;
        }

        // Lo que se le pidió en el mensaje anterior.
        if ($expect === 'postcode') {
            $postcode = $this->postcodeOf($data, $n);
            if ($postcode !== '') {
                $this->shipping($postcode);

                return;
            }
        }
        if ($expect === 'order' && ($number !== '' || $email !== '' || isset($this->state['order_number']))) {
            $this->order($number, $email);

            return;
        }

        // Saludos y cortesías (solo si no hay nada más que decir).
        $rest = trim((string) preg_replace(self::RE_GREETING_WORDS, ' ', $n));
        if ($n !== '' && preg_match(self::RE_GREETING_START, $n) === 1 && $rest === '') {
            $this->say(t('Hello! I can help you find products, quote the shipping, check an order or answer your questions about returns and payment. What are you looking for?'));
            $this->chips(['returns', 'payment', 'order']);

            return;
        }
        if (count($words) <= 5 && preg_match(self::RE_THANKS, $n) === 1) {
            $this->say(t('You are welcome! Tell me if you need anything else.'));

            return;
        }
        if (count($words) <= 4 && preg_match(self::RE_BYE, $n) === 1) {
            $this->say(t('Goodbye! I am here whenever you need me.'));

            return;
        }

        // Una consulta que nombra un artículo puede contener «buy» u «order» sin confirmar un carrito.
        $catalogueQuery = self::catalogueRequest($n);
        if ($catalogueQuery !== null && !$this->dialog->active()) {
            $this->search($message, 'busco ' . $catalogueQuery);
            return;
        }

        if (preg_match('/\b(?:cuando (?:me )?llegaria|when (?:will|would) (?:it|my order|the order) arrive)\b/', $n) === 1) {
            $postcode = $this->postcodeOf($data, $n);
            if ($postcode !== '') {
                $this->shipping($postcode);
            } else {
                $this->say(t('What is your postcode? I will quote the shipping for you.'));
                $this->answer->expect = 'postcode';
            }
            return;
        }
        // La compra dentro del chat: los datos, el envío y el pago de un pedido empezado, o empezar uno.
        if ($this->dialog->active() && $this->dialog->handle($message, $n, $data, self::isQuestion($message, $n))) {
            $this->inOrder = true;

            return;
        }
        if ($this->dialog->available() && preg_match(self::RE_PLACE, $n) === 1 && preg_match(self::RE_CHECKOUT_PAGE, $n) !== 1) {
            if ($this->dialog->start()) {
                $this->inOrder = true;
            } else {
                // Nada que pedir todavía: el carrito está vacío.
                $this->checkout();
            }

            return;
        }

        if (preg_match(self::RE_COMPARE, $n) === 1 && count($this->stateCards()) >= 2) {
            $this->compare();

            return;
        }
        if (self::asksOrderData($message)) {
            // «Dame los datos del pedido 2026-000011»: los datos de un pedido solo se ven en la cuenta de quien lo hizo.
            $this->orderAccount();

            return;
        }
        if (preg_match(self::RE_ORDER, $n) === 1 || ($email !== '' && $number !== '')) {
            $this->order($number, $email);

            return;
        }
        if (preg_match(self::RE_RETURNS, $n) === 1 && $this->postcodeOf($data, $n) === '') {
            // «¿Puedo devolverla? ¿Quién paga el envío de vuelta?»: es una pregunta de devoluciones, aunque diga «envío».
            $this->returns($message);

            return;
        }
        // La factura y la forma de pago, antes que el envío: «pago a la entrega» lleva «entrega» y no pregunta por el envío.
        if (preg_match(self::RE_INVOICE_INFO, $n) === 1) {
            $this->invoices();

            return;
        }
        if ($this->runner->can('list_subscription_plans') && preg_match('/\b(?:suscripci\w*|planes? mensuales?|servicios? mensuales?|pagos? recurrentes?|subscription\w*|monthly (?:plans?|services?|payments?)|recurring payments?)\b/', $n) === 1) {
            $result = $this->runner->run('list_subscription_plans', []);
            $lines = [];
            foreach (isset($result['plans']) && is_array($result['plans']) ? $result['plans'] : [] as $plan) {
                $lines[] = (string) $plan['name'] . ': ' . (string) $plan['base_price_formatted'] . ' ' . t('per month, before billing-address tax adjustments');
            }
            $this->say($lines !== [] ? implode("\n", $lines) . "\n" . (string) $result['note'] : t('Monthly plans are not available right now.'));

            return;
        }
        if (preg_match(self::RE_PAYMENT_INFO, $n) === 1) {
            $this->payment();

            return;
        }
        if (preg_match(self::RE_SHIPPING, $n) === 1) {
            $postcode = $this->postcodeOf($data, $n);
            if ($postcode !== '') {
                $this->shipping($postcode);
            } else {
                $this->say(t('What is your postcode? I will quote the shipping for you.'));
                $this->answer->expect = 'postcode';
            }

            return;
        }
        if (preg_match(self::RE_CHECKOUT, $n) === 1) {
            // «Quiero pagar»: se hace el pedido aquí. «Ir a la caja» sigue llevando a la caja de la web.
            if ($this->dialog->available() && preg_match(self::RE_CHECKOUT_PAGE, $n) !== 1 && $this->dialog->start()) {
                $this->inOrder = true;

                return;
            }
            $this->checkout();

            return;
        }
        if (preg_match(self::RE_RETURNS, $n) === 1) {
            $this->returns();

            return;
        }
        if (preg_match(self::RE_HOURS, $n) === 1) {
            $this->hours();

            return;
        }
        if (preg_match(self::RE_CONTACT, $n) === 1) {
            $this->contact();

            return;
        }
        // «Añade el primero», o «añádelo al carrito» a secas cuando solo se ha enseñado un producto.
        $shown = $this->stateCards();
        $asked = self::qtyAsked($n);
        $rest = $asked !== null ? $asked['rest'] : $n;
        if (preg_match(self::RE_ADD, $n) === 1 && ($this->ordinal($rest) !== null || (count($shown) === 1 && ($asked !== null || preg_match(self::RE_CART, $n) === 1)))) {
            $this->addFromList($n);

            return;
        }
        // «¿Y en la talla 42?» sobre ese único producto: se dice si la hay, sin añadir nada.
        if (count($shown) === 1 && preg_match(self::RE_OTHER, $n) !== 1 && $this->optionQuestion($message, $shown[0])) {
            return;
        }
        // «Y si pido 2 sillas baratas, ¿cuánto sale?»: el total de esas unidades lo calcula la tienda.
        if ($shown !== [] && QtyQuote::wanted($message) >= 2) {
            $quote = QtyQuote::quote($this->runner, $message, $shown);
            if ($quote !== null) {
                $this->say($quote['line']);
                $this->chips(['cart', 'shipping']);

                return;
            }
        }
        if (preg_match(self::RE_CART, $n) === 1 && (count($words) <= 3 || preg_match(self::RE_CART_VIEW, $n) === 1)) {
            $this->cart();

            return;
        }
        // «Háblame de X», «soy hombre» tras una búsqueda, «para una boda», «otra cosa», «seguir mirando»: el consejo. Lo que afina no
        // se busca tal cual.
        if ($this->advice !== null && $this->advice->rules($message, $this->kind, $this->need)) {
            return;
        }

        $this->search($message, $n);
    }

    // ------------------------------------------------------------------ intenciones

    /** Buscar productos: con un «busco…» de por medio o con un mensaje corto que parece lo que se busca. */
    private function search(string $message, string $n): void
    {
        list($query, $max, $triggered) = $this->searchQuery($n);
        $words = $query === '' ? 0 : count(explode(' ', $query));
        $question = strpos($message, '?') !== false || strpos($message, '¿') !== false || preg_match(self::RE_QUESTION_START, $n) === 1;
        $looksLikeSearch = $triggered || ($words >= 1 && $words <= 4 && !$question);

        if (!$looksLikeSearch) {
            $this->unknown();

            return;
        }
        if ($query === '') {
            $this->say(t('What are you looking for? Tell me the product or the category.'));
            $this->chips(['returns', 'payment', 'order']);

            return;
        }

        $input = ['query' => mb_substr($query, 0, 120, 'UTF-8'), 'limit' => 5];
        if ($max !== null) {
            $input['max_price_cents'] = $max;
        }
        $result = $this->runner->run('search_products', $input);
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $kind = isset($result['answer_kind']) ? (string) $result['answer_kind'] : ($items === [] ? 'none' : 'exact');
        if ($kind === 'shop' && isset($result['shop_answer']) && trim((string) $result['shop_answer']) !== '') {
            // Una pregunta sobre la tienda («¿cuánto tarda el envío?»): la contesta el buscador con los datos de la tienda.
            $this->say(trim((string) $result['shop_answer']));
            return;
        }
        if (isset($result['error']) || $items === []) {
            $this->say(t('I found nothing for «{q}». Try other words or choose a category.', ['q' => $query]));
            $this->answer->unanswered = true;
            $categories = $this->runner->run('list_categories', []);
            foreach (isset($categories['categories']) && is_array($categories['categories']) ? array_slice($categories['categories'], 0, 3) : [] as $category) {
                $this->answer->suggestions[] = (string) $category['name'];
            }
            return;
        }
        if ($kind === 'relaxed' || $kind === 'related') {
            // Nada exacto: lo más parecido se enseña como lo que es, nunca como si fuera lo buscado.
            $this->say(t('I found nothing exact for «{q}». These are the closest:', ['q' => $query]));
            $this->runner->presentSearch(Answer::MAX_CARDS, true);
            $this->soldOut();
            $this->chips(['add', 'shipping', 'cart']);
            $this->refineChips();
            return;
        }
        $text = t('Here are the best matches for «{q}»:', ['q' => isset($result['corrected']) ? (string) $result['corrected'] : $query]);
        if (isset($result['corrected'])) {
            $text = t('Showing results for «{q}», the closest to what you typed:', ['q' => (string) $result['corrected']]);
        }
        $this->say($text);
        $this->runner->presentSearch(Answer::MAX_CARDS);
        $this->soldOut();
        $this->chips(['add', 'shipping', 'cart']);
        $this->refineChips();
    }

    /** Tras una lista, los botones de afinar delante («Para hombre», «Hasta 60,00 €»), si hay consejo en este mensaje. */
    private function refineChips(): void
    {
        if ($this->advice !== null) {
            $this->advice->refineFirst();
        }
    }

    /** Un agotado se enseña diciendo que lo está: una línea por cada ficha agotada de la respuesta. */
    private function soldOut(): void
    {
        $lines = [];
        foreach ($this->answer->cards as $card) {
            if (isset($card['status']) && $card['status'] === 'out_of_stock') {
                $lines[] = t('{name} is sold out right now.', ['name' => (string) $card['name']]);
            }
        }
        if ($lines !== []) {
            $this->answer->text = trim($this->answer->text . "\n" . implode("\n", array_slice($lines, 0, 3)));
        }
    }

    /**
     * Cuando la IA no ha llegado a contestar y las reglas no entienden la frase: si nombra o describe productos («busco una silla
     * de oficina», «compárame las dos sillas de oficina que tenéis»), se busca con el buscador de la tienda (Search::ask(), por la
     * herramienta search_products) y se enseña lo que haya, con los datos de comparación si son dos o más. False si no hay nada
     * que enseñar: entonces sí se dice que no se ha podido contestar.
     */
    public function showFromPhrase(string $message): bool
    {
        foreach (self::productPhrases($message) as $phrase) {
            $result = $this->runner->run('search_products', ['query' => mb_substr($phrase, 0, 120, 'UTF-8'), 'limit' => 5]);
            $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
            $kind = isset($result['answer_kind']) ? (string) $result['answer_kind'] : '';
            if (isset($result['error']) || $items === [] || !in_array($kind, ['exact', 'relaxed', 'related'], true)) {
                continue;
            }
            $this->gaveUp = false;
            $this->answer->unanswered = false;
            $this->answer->suggestions = [];
            $this->answer->text = $kind === 'exact'
                ? t('This is what I have of {q}:', ['q' => $phrase])
                : t('I found nothing exact for «{q}». These are the closest:', ['q' => $phrase]);
            $this->runner->presentSearch(Answer::MAX_CARDS, true);
            $ids = [];
            foreach (array_slice($this->answer->cards, 0, 3) as $card) {
                $ids[] = (int) $card['id'];
            }
            if (count($ids) >= 2) {
                $compared = $this->runner->run('compare_products', ['ids' => $ids]);
                if (!isset($compared['error'])) {
                    $this->answer->text .= "\n" . implode("\n", self::comparison($compared));
                }
            }
            $this->soldOut();
            $this->chips(['add', 'shipping', 'cart']);

            return true;
        }

        return false;
    }

    /**
     * Lo que nombra como producto una frase larga, en el orden en que lo dice: lo que va detrás de «busco», «quiero», «tenéis»,
     * «comparar las dos…», «looking for»… dentro de cada frase, sin artículos ni lo que viene después («que tenéis», «para…»).
     * Con las palabras del cliente (tildes incluidas).
     *
     * @return string[]
     */
    public static function productPhrases(string $message): array
    {
        $out = [];
        $lead = ['busco', 'buscando', 'buscar', 'quiero', 'quisiera', 'necesito', 'tienes', 'teneis', 'tienen', 'hay', 'comparar', 'compara',
            'comparame', 'comparas', 'compare', 'recomiendame', 'recomienda', 'ensename', 'muestrame', 'want', 'need', 'have', 'for', 'show'];
        $skip = ['un', 'una', 'unos', 'unas', 'el', 'la', 'los', 'las', 'dos', 'tres', 'some', 'a', 'an', 'the', 'any', 'two', 'me', 'te',
            'algo', 'alguna', 'alguno', 'me', 'you', 'your', 'me', 'por', 'favor', 'please', 'looking'];
        $stop = ['que', 'y', 'para', 'con', 'pero', 'porque', 'si', 'o', 'and', 'that', 'which', 'for', 'with', 'but', 'or', 'please', 'por'];
        foreach (preg_split('/[.!?¿¡;:,\n]+/u', $message) ?: [] as $sentence) {
            $words = preg_split('/[^\p{L}\p{N}\'-]+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
            $words = is_array($words) ? $words : [];
            $norm = array_map(static function (string $word): string {
                return RuleBrain::norm($word);
            }, $words);
            foreach ($norm as $i => $word) {
                if (!in_array($word, $lead, true)) {
                    continue;
                }
                $phrase = [];
                for ($j = $i + 1; $j < count($words) && count($phrase) < 5; ++$j) {
                    if ($phrase === [] && in_array($norm[$j], $skip, true)) {
                        continue;
                    }
                    if ($phrase !== [] && in_array($norm[$j], $stop, true)) {
                        break;
                    }
                    if (in_array($norm[$j], $lead, true) && $phrase === []) {
                        continue;
                    }
                    $phrase[] = mb_strtolower($words[$j], 'UTF-8');
                }
                // «de oficina» no puede acabar en «de».
                while ($phrase !== [] && in_array(RuleBrain::norm((string) end($phrase)), ['de', 'del', 'of'], true)) {
                    array_pop($phrase);
                }
                if ($phrase !== [] && !in_array(implode(' ', $phrase), $out, true)) {
                    $out[] = implode(' ', $phrase);
                }
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Las líneas de una comparación: precio y existencias de cada uno y en qué se diferencian, con los datos de la ficha.
     *
     * @param array<string,mixed> $result lo que devolvió compare_products
     *
     * @return string[]
     */
    private static function comparison(array $result): array
    {
        $lines = [];
        $names = [];
        foreach (isset($result['products']) && is_array($result['products']) ? $result['products'] : [] as $row) {
            $lines[] = '• ' . (string) $row['name'] . ' — ' . (string) $row['price'] . ((string) $row['availability'] !== '' ? ' (' . (string) $row['availability'] . ')' : '');
            if (isset($row['per_unit'])) { $lines[] = $row['per_unit']; }
            if (isset($row['pack_saving'])) { $lines[] = $row['pack_saving']; }
            $names[(int) $row['id']] = (string) $row['name'];
        }
        foreach (isset($result['differences']) && is_array($result['differences']) ? $result['differences'] : [] as $difference) {
            $parts = [];
            foreach (is_array($difference['values']) ? $difference['values'] : [] as $value) {
                $parts[] = (isset($names[(int) $value['id']]) ? $names[(int) $value['id']] : '') . ': ' . (string) $value['value'];
            }
            $lines[] = (string) $difference['name'] . ' — ' . implode('; ', $parts);
        }

        return $lines;
    }

    /**
     * ¿Pide los datos de un pedido concreto («dame los datos y la dirección del pedido 2026-000011»)? Solo con el número del pedido
     * delante: «mis datos para el pedido: …» es quien da los suyos.
     */
    public static function asksOrderData(string $message): bool
    {
        $n = self::norm($message);

        return preg_match('/\b\d{4}-\d{3,8}\b/', $n) === 1
            && preg_match('/\b(?:datos|direccion|domicilio|nombre|telefono|a quien|quien lo|address|details|data|personal|name|phone|who)\b/', $n) === 1;
    }

    /**
     * Los datos de un pedido no se dan por el chat a quien escribe un número: solo los ve, en «Mi cuenta», quien ha entrado con la
     * cuenta que lo hizo. No se pide un correo «para verificar».
     */
    public function answerOrderData(string $message): bool
    {
        if (!self::asksOrderData($message)) {
            return false;
        }
        $this->orderAccount();

        return true;
    }

    private function orderAccount(): void
    {
        $info = $this->runner->run('store_info', ['topic' => 'contact']);
        $url = isset($info['account_url']) ? (string) $info['account_url'] : '';
        $this->say($url !== ''
            ? t('I can only show the orders of the account you are signed in with. If it is yours, sign in to «My account» to see it: {url}', ['url' => $url])
            : t('I can only show the orders of the account you are signed in with. If it is yours, sign in to «My account» to see it.'));
        $this->answer->expect = '';
    }

    /**
     * ¿Habla el mensaje de la tienda o de la compra (un pedido, el envío, el pago, las devoluciones, el contacto, el horario, el
     * carrito, añadir, comparar, datos del cliente) o es solo un saludo, unas gracias o una despedida? Entonces no es una necesidad
     * de producto (Need::kind) y no se busca con él.
     */
    public static function shopTopic(string $message): bool
    {
        $data = CustomerData::extract($message);
        if (isset($data['email']) || isset($data['phone']) || isset($data['postcode']) || isset($data['tax_id'])) {
            return true;
        }
        $n = self::norm($message);
        if ($n === '') {
            return false;
        }
        $words = explode(' ', $n);
        $rest = trim((string) preg_replace(self::RE_GREETING_WORDS, ' ', $n));
        if ((preg_match(self::RE_GREETING_START, $n) === 1 && $rest === '') || (count($words) <= 5 && preg_match(self::RE_THANKS, $n) === 1)
            || (count($words) <= 4 && preg_match(self::RE_BYE, $n) === 1)) {
            return true;
        }
        foreach ([self::RE_ORDER, self::RE_SHIPPING, self::RE_INVOICE_INFO, self::RE_PAYMENT_INFO, self::RE_CHECKOUT, self::RE_PLACE, self::RE_RETURNS,
            self::RE_HOURS, self::RE_CART, self::RE_ADD, self::RE_COMPARE] as $pattern) {
            if (preg_match($pattern, $n) === 1) {
                return true;
            }
        }
        // «Hablar con una persona», «teléfono»: el contacto; «para una persona mayor» no lo es.
        return preg_match(self::RE_CONTACT, $n) === 1 && preg_match('/\bpara (?:una |un |mi )?persona\b/', $n) !== 1;
    }

    /** Separa la petición de consulta del nombre, conservando cantidades, tallas y presupuesto para el buscador. */
    private static function catalogueRequest(string $text): ?string
    {
        $original = $text;
        // Un inciso sobre la ortografía termina al empezar la petición concreta de catálogo.
        if (preg_match('/\b(?:mal escrito|escrib\w* mal|faltas de ortografia|misspell\w*|spelt wrong|spelled wrong)\b.{0,60}?\b(teneis|vendeis|busco|do you sell|do you have|i am looking for)\s+(.+)$/', $text, $asked)) {
            $text = $asked[1] . ' ' . $asked[2];
        }
        $text = preg_replace('/^(?:(?:por favor|porfa|oye|hola|please|hey|en (?:vuestro|el) catalogo|estoy mirando opciones|no quiero comprar nada)\s+)+/', '', $text);
        $prefixes = [
            'do you sell|vendeis|venden|vendes|teneis disponible|hay existencias de|hay stock de|hay unidades de',
            '(?:me |nos )?(?:ensenas|mostrais|muestras)|(?:puedes |podrias )?(?:ensenarme|mostrarme)(?: (?:una |la )?ficha de)?',
            'busca(?:me)?|(?:querria|quisiera) (?:ver|comprar)|donde (?:puedo )?(?:encontrar|encuentro)|(?:me )?ayudas a (?:buscar|encontrar)',
            '(?:solo )?(?:te )?pregunto por|(?:necesito|quisiera) informacion (?:de|sobre)|(?:estoy )?buscando algo concreto',
            '(?:can|could|would) you (?:show me|find|check (?:the )?(?:price|availability) (?:of|for))',
            '(?:where can i|help me) find|find (?:this |the )?product(?: for me)?|(?:i want to|can i|could i) (?:buy|order)',
        ];
        if ($text !== $original) {
            $prefixes[] = 'hay|teneis|busco';
        }
        if (preg_match('/^(?:' . implode('|', $prefixes) . ')\s+(.+)$/', $text, $match) !== 1) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+(?:porfa|por favor|please|here)$/', '', $match[1]));
        if ($name === '' || preg_match('/^(?:it|this|that|now|lo|esto|eso|el pedido|mi pedido|the order|my order)$/', $name) === 1) {
            return null;
        }
        return $name;
    }

    /**
     * Lo que se busca en un mensaje ya normalizado: sin «busco», «quiero», artículos ni «por favor», y el precio máximo si lo dice
     * («por menos de 60 €»).
     *
     * @return array{0:string,1:?int,2:bool} el texto que se busca, el precio máximo en céntimos y si venía con un «busco…»
     */
    public static function searchQuery(string $n): array
    {
        $max = null;
        if (preg_match('/\b(?:por menos de|no mas de|menos de|hasta|maximo|bajo|under|below|less than|up to|max)\s+(\d{1,6})(?:[.,](\d{1,2}))?\s*(?:€|euros?|eur)?/', $n, $m) === 1) {
            $cents = (int) $m[1] * 100;
            if (isset($m[2]) && $m[2] !== '') {
                $cents += (int) str_pad($m[2], 2, '0');
            }
            $max = $cents > 0 ? $cents : null;
            $n = trim(str_replace($m[0], ' ', $n));
        }
        $n = trim(str_replace('€', ' eur ', $n));
        $n = trim((string) preg_replace('/\s+/', ' ', $n));

        $triggered = false;
        $lead = '/^(?:(?:hola|buenas|buenos dias|buenas tardes|por favor|oye|hey)\s+)*(?:(?:quisiera|quiero|busco|buscar|buscando|necesito|queria|estoy buscando|andaba buscando|tenei?s|tienen|tienes|teneis|hay|me gustaria|muestrame|ensename|recomiendame|recomienda|dame|cuanto (?:cuesta|vale|valen|cuestan)|precio de(?:l| la| los| las)?|how much (?:is|are|does)|price of|i want|i need|i am looking for|im looking for|looking for|do you have|have you got|show me|search for|search|find me|find)\s+)+/';
        if (preg_match($lead, $n . ' ', $m) === 1) {
            $triggered = true;
            $n = trim((string) substr($n . ' ', strlen($m[0])));
        }
        $n = trim((string) preg_replace('/^(?:(?:comprar|ver|encontrar|un|una|unos|unas|el|la|los|las|algo de|algun|alguna|algunos|algunas|some|a|an|the|any|de)\s+)+/', '', $n . ' '));
        $n = trim((string) preg_replace('/\s+(?:por favor|please|gracias|por|de|a|que cuesten|que cuesta)$/', '', $n));

        return [$n, $max, $triggered];
    }

    /** Consulta de un pedido: necesita el número y el correo; lo que falte se pide, recordando lo que ya se dijo. */
    private function order(string $number, string $email): void
    {
        if ($number === '' && isset($this->state['order_number'])) {
            $number = (string) $this->state['order_number'];
        }
        if ($number === '' && $email === '') {
            $this->say(t('To check an order I need the order number and the email you used when you placed it.'));
            $this->answer->expect = 'order';

            return;
        }
        if ($number === '') {
            $this->say(t('What is the order number?'));
            $this->answer->expect = 'order';

            return;
        }
        if ($email === '') {
            $this->say(t('Which email did you use for the order?'));
            $this->answer->expect = 'order';
            $this->answer->remember['order_number'] = $number;

            return;
        }
        $result = $this->runner->run('order_status', ['number' => $number, 'email' => $email]);
        if (isset($result['error'])) {
            $this->say(rtrim((string) $result['error'], '.') . '. ' . t('Check the number and the email, or write to us.'));

            return;
        }
        $lines = [t('Order {number}: {status}.', ['number' => (string) $result['number'], 'status' => (string) $result['status_label']])];
        if (isset($result['carrier'])) {
            $lines[] = t('Shipped with {carrier}.', ['carrier' => (string) $result['carrier']]);
        }
        if (isset($result['tracking_code'])) {
            $lines[] = t('Tracking code: {code}', ['code' => (string) $result['tracking_code']]);
        }
        $this->say(implode("\n", $lines));
    }

    /** Cuánto cuesta enviar a un código postal: el carrito si lo hay y, si no, el primer producto que se enseñó. */
    private function shipping(string $postcode, string $country = ''): void
    {
        $destination = ['postcode' => $postcode];
        if ($country !== '') {
            $destination['country'] = $country;
        }
        $result = $this->runner->run('shipping_quote', $destination);
        if (isset($result['need']) && $result['need'] === 'product') {
            $cards = $this->stateCards();
            if ($cards === []) {
                $this->say(t('Tell me the product you want to send, or add something to the cart, and I will quote the shipping.'));
                $this->answer->expect = 'postcode';

                return;
            }
            $result = $this->runner->run('shipping_quote', $destination + ['product_id' => (int) $cards[0]['id']]);
        }
        if (isset($result['error'])) {
            $this->say((string) $result['error']);

            return;
        }
        if (isset($result['needs_shipping']) && $result['needs_shipping'] === false) {
            $this->say(t('That does not need shipping.'));

            return;
        }
        $options = isset($result['options']) && is_array($result['options']) ? $result['options'] : [];
        if ($options === []) {
            $this->say(t('Sorry, we do not deliver to {postcode} yet. Write to us and we will look for a solution.', ['postcode' => $postcode]));

            return;
        }
        $lines = [t('Shipping to {postcode}:', ['postcode' => $postcode])];
        foreach ($options as $option) {
            $price = !empty($option['free']) ? t('Free') : (string) $option['price'];
            $delay = (string) $option['delay'] !== '' ? ' (' . (string) $option['delay'] . ')' : '';
            $estimate = isset($option['estimated_dates']) ? ' · ' . t('Estimated arrival: {dates}', ['dates' => $option['estimated_dates']]) : '';
            $lines[] = '• ' . (string) $option['name'] . ': ' . $price . $delay . $estimate;
        }
        if (!empty($result['destination_notice'])) { $lines[] = $result['destination_notice']; }
        if (($result['free_shipping_gap_cents'] ?? 0) > 0) {
            $lines[] = t('Add {amount} in products to reach the free delivery threshold for this destination.', ['amount' => $result['free_shipping_gap']]);
            if (!empty($result['complements'])) {
                $lines[] = t('Adding one of these items would qualify this parcel for free delivery.');
                foreach ($result['complements'] as $card) {
                    $this->answer->addCard($card);
                }
            }
        }
        $this->say(implode("\n", $lines));
        $this->chips(['cart', 'checkout']);
    }

    private function payment(): void
    {
        $result = $this->runner->run('store_info', ['topic' => 'payment']);
        $methods = isset($result['payment_methods']) && is_array($result['payment_methods']) ? $result['payment_methods'] : [];
        if ($methods === []) {
            $this->say(t('You will see the payment methods available for your order at checkout.'));
        } else {
            $this->say(t('You can pay with: {methods}.', ['methods' => implode(', ', array_map('strval', $methods))]));
        }
        $this->chips(['cart', 'returns']);
    }

    /** Cómo llega la factura en esta tienda (InvoiceNotice: adjunta a la confirmación, en la cuenta o por correo desde el ERP). */
    private function invoices(): void
    {
        $result = $this->runner->run('store_info', ['topic' => 'payment']);
        $text = isset($result['invoices']) && is_string($result['invoices']) ? trim($result['invoices']) : '';
        $this->say($text !== '' ? $text : t('You will receive the invoice of your order by email.'));
        $this->chips(['order', 'payment']);
    }

    /** Compara los productos que se acaban de enseñar (hasta tres) con los datos de la ficha: precio, existencias y en qué se diferencian. */
    private function compare(): void
    {
        $ids = [];
        foreach (array_slice($this->stateCards(), 0, 3) as $card) {
            $ids[] = (int) $card['id'];
        }
        $result = $this->runner->run('compare_products', ['ids' => $ids]);
        if (isset($result['error'])) {
            $this->say((string) $result['error']);

            return;
        }
        $this->say(implode("\n", array_merge([t('This is how they compare:')], self::comparison($result))));
        $this->runner->run('show_products', ['ids' => $ids]);
        $this->chips(['add', 'shipping', 'cart']);
    }

    private function returns(string $question = ''): void
    {
        $result = $this->runner->run('store_info', ['topic' => 'all']);
        $text = isset($result['returns']) ? trim((string) $result['returns']) : '';
        foreach ([
            'reembols|refund' => 'reembols|refund',
            'quien paga|cuanto cuesta|coste|cost|who pays' => 'coste|cuesta|paga|cargo|cost|pays|charge',
            'plazo|cuantos dias|how long|time limit' => 'plazo|dias|days|within',
            'como|tramita|solicita|how do|how to' => 'solicita|tramita|formulario|escribe|request|form|contact',
        ] as $intent => $evidence) {
            if (!preg_match('/(?:' . $intent . ')/', self::norm($question))) { continue; }
            $lines = [];
            $published = $text;
            foreach ($result['pages'] ?? [] as $policyPage) {
                if (($policyPage['topic'] ?? '') === 'returns') { $published .= "\n" . ($policyPage['text'] ?? ''); }
            }
            foreach (preg_split('/(?<=[.!?])\s+|\R/u', $published) ?: [] as $line) {
                if (preg_match('/(?:' . $evidence . ')/', self::norm($line))) { $lines[] = trim($line); }
            }
            if ($lines) { $text = implode(' ', array_slice(array_unique($lines), 0, 3)); }
            break;
        }
        $link = '';
        foreach (isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : [] as $page) {
            if (is_array($page) && isset($page['topic'], $page['url']) && $page['topic'] === 'returns' && (string) $page['url'] !== '') {
                $link = (string) $page['url'];
                break;
            }
        }
        if ($link === '' && isset($result['returns_url']) && (string) $result['returns_url'] !== '') {
            // La página del módulo de devoluciones, con su dominio.
            $link = (string) $result['returns_url'];
        }
        if ($text !== '' || $link !== '') {
            if (isset($result['returns_payer_note'])) {
                // La tienda no ha dicho quién paga la vuelta: se dice cómo preguntárselo.
                $text = trim($text . ' ' . $this->contactLine($result));
            }
            $more = $link !== '' ? t('More details: {url}', ['url' => $link]) : '';
            $this->say(trim($text . ($text !== '' && $more !== '' ? "\n" : '') . $more));
            return;
        }
        $this->say(t('I do not have the returns policy at hand.') . ' ' . $this->contactLine($result));
        $this->answer->unanswered = true;
    }

    private function hours(): void
    {
        $result = $this->runner->run('store_info', ['topic' => 'all']);
        $text = isset($result['hours']) ? trim((string) $result['hours']) : '';
        if ($text !== '') {
            $this->say(t('Our opening hours: {hours}', ['hours' => $text]));

            return;
        }
        $this->say(t('I do not have the opening hours at hand.') . ' ' . $this->contactLine($result));
        $this->answer->unanswered = true;
    }

    private function contact(): void
    {
        $result = $this->runner->run('store_info', ['topic' => 'contact']);
        $contact = isset($result['contact']) && is_array($result['contact']) ? $result['contact'] : [];
        if ($contact === []) {
            $this->say(t('The shop has not published contact details yet.'));
            $this->answer->unanswered = true;

            return;
        }
        $lines = [t('You can reach the shop here:')];
        if (isset($contact['email'])) {
            $lines[] = t('Email: {value}', ['value' => (string) $contact['email']]);
        }
        if (isset($contact['phone'])) {
            $lines[] = t('Phone: {value}', ['value' => (string) $contact['phone']]);
        }
        if (!empty($contact['whatsapp'])) { $lines[] = '[WhatsApp](' . $contact['whatsapp'] . ')'; }
        if (isset($contact['address'])) {
            $lines[] = t('Address: {value}', ['value' => (string) $contact['address']]);
        }
        $this->say(implode("\n", $lines));
    }

    private function cart(): void
    {
        $result = $this->runner->run('cart_view', []);
        if (isset($result['error'])) {
            $this->say((string) $result['error']);

            return;
        }
        $lines = isset($result['lines']) && is_array($result['lines']) ? $result['lines'] : [];
        if ($lines === []) {
            $this->say(t('Your cart is empty.'));

            return;
        }
        $text = [t('In your cart:')];
        foreach ($lines as $line) {
            $text[] = (int) $line['qty'] . ' × ' . (string) $line['name'] . ' — ' . (string) $line['total'];
        }
        $text[] = t('Total: {total}', ['total' => (string) $result['total']]);
        $this->say(implode("\n", $text));
        $this->chips($this->dialog->available() ? ['place', 'checkout', 'shipping'] : ['checkout', 'shipping']);
    }

    private function checkout(): void
    {
        $result = $this->runner->run('checkout_link', []);
        if (isset($result['error'])) {
            $this->say(rtrim((string) $result['error'], '.') . '. ' . t('Tell me what you are looking for and I will help you.'));

            return;
        }
        $text = [t('Your cart is ready. Review it and pay securely at checkout.')];
        foreach (isset($result['problems']) && is_array($result['problems']) ? $result['problems'] : [] as $problem) {
            $text[] = '• ' . (string) $problem;
        }
        $this->say(implode("\n", $text));
        if ($this->dialog->available()) {
            $this->chips(['place']);
        }
    }

    /** «Añade el primero»: sobre las fichas que se acaban de enseñar. */
    private function addFromList(string $n): void
    {
        $asked = self::qtyAsked($n);
        $index = $this->ordinal($asked !== null ? $asked['rest'] : $n);
        $cards = $this->stateCards();
        if ($index === null && count($cards) === 1) {
            // Solo hay uno a la vista: «añádelo al carrito» es ese.
            $index = 0;
        }
        if ($index === null || !isset($cards[$index])) {
            $this->say(t('Tell me which product to add: look for it first and I will show it to you.'));
            return;
        }
        $card = $cards[$index];
        $qty = $asked !== null ? $asked['qty'] : 1;
        $result = $this->runner->run('cart_add', ['product_id' => (int) $card['id'], 'qty' => $qty]);
        if (isset($result['need']) && $result['need'] === 'options') {
            // Un producto con variantes: se pregunta cuál, con botones, y la respuesta (el botón o el texto) lo añade.
            $this->say(t('«{name}» comes in several options. Which one do you want?', ['name' => (string) $card['n']]));
            return;
        }
        if (isset($result['error'])) {
            $text = (string) $result['error'];
            if (isset($result['choices']) && is_array($result['choices']) && $result['choices'] !== []) {
                // Pidió una talla o un color que no hay: se dicen las que sí (y van también como botones).
                $text .= ' ' . t('Available: {list}.', ['list' => implode(', ', array_map('strval', $result['choices']))]);
            }
            $this->say($text);
            return;
        }
        $this->sayAdded($result, (string) $card['n']);
    }

    /**
     * Lo que se dice tras «añadir»: lo añadido con el total del carrito y, si eran varias unidades, lo que cuestan («2 × Silla a
     * 89,00 € son 178,00 €»); o, si ya estaba en el carrito y no se pidió más, que ya está y con cuántas unidades.
     *
     * @param array<string,mixed> $result lo que devolvió cart_add
     */
    private function sayAdded(array $result, string $fallbackName): void
    {
        $name = (string) (isset($result['name']) && (string) $result['name'] !== '' ? $result['name'] : $fallbackName);
        if (!empty($result['already_in_cart'])) {
            $qty = (int) $result['qty_in_cart'];
            $label = $name . ((string) $result['variant'] !== '' ? ' (' . (string) $result['variant'] . ')' : '');
            $this->say(tn('«{name}» is already in your cart: {n} unit. Tell me how many you want if you want more.', '«{name}» is already in your cart: {n} units. Tell me how many you want if you want more.', $qty, ['name' => $label]));
            $this->chips(['cart', 'checkout']);

            return;
        }
        $text = t('Added to your cart: {name}.', ['name' => $name]);
        $qty = (int) (isset($result['qty']) ? $result['qty'] : 1);
        if ($qty > 1 && isset($result['unit_price'], $result['added_total'])) {
            $text .= ' ' . t('{qty} × {name} at {unit} make {total}.', ['qty' => $qty, 'name' => $name, 'unit' => (string) $result['unit_price'], 'total' => (string) $result['added_total']]);
        }
        $this->say($text . ' ' . t('Total: {total}', ['total' => (string) $result['cart']['total']]));
        // Añadido no es querer pagar: se ofrece ir a la caja, ver el carrito o seguir mirando, y no se piden datos.
        $this->answer->suggestions = array_merge($this->answer->suggestions, Replies::afterAdd());
    }

    /**
     * «¿Y en la talla 42?», «¿lo hay en azul?» sobre el único producto que se acaba de enseñar: dice si esa opción se puede comprar,
     * sin añadir nada. False si el mensaje no pregunta por ninguna opción (se atiende como cualquier otro).
     *
     * @param array<string,mixed> $card
     */
    private function optionQuestion(string $message, array $card): bool
    {
        $asks = OptionAsk::wanted($message);
        if ($asks === []) {
            return false;
        }
        $product = $this->runner->run('get_product', ['id' => (int) $card['id']]);
        if (isset($product['error'])) {
            return false;
        }
        $have = [];
        $sold = [];
        foreach (isset($product['options']) && is_array($product['options']) ? $product['options'] : [] as $option) {
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $label) {
                $have[] = (string) $label;
            }
            foreach (isset($option['sold_out']) && is_array($option['sold_out']) ? $option['sold_out'] : [] as $label) {
                $sold[] = (string) $label;
            }
        }
        if ($have === [] && $sold === []) {
            $this->say(t('This product has no sizes, colours or other options to choose: it is sold as shown.'));

            return true;
        }
        $list = $have !== [] ? ' ' . t('Available: {list}.', ['list' => implode(', ', array_slice($have, 0, 20))]) : '';
        foreach ($asks as $ask) {
            if ($ask['value'] === '') {
                continue;
            }
            foreach ($have as $label) {
                if (OptionAsk::same($ask['value'], $label)) {
                    $this->say(t('Yes, «{option}» is available.', ['option' => $label]));
                    $this->answer->suggestions[] = t('Add «{option}» to the cart', ['option' => $label]);

                    return true;
                }
            }
            foreach ($sold as $label) {
                if (OptionAsk::same($ask['value'], $label)) {
                    $this->say(t('«{option}» is sold out.', ['option' => $label]) . $list);

                    return true;
                }
            }
            $this->say(t('«{option}» is not available for this product.', ['option' => $ask['text']]) . $list);

            return true;
        }
        // Pregunta si hay tallas o colores, sin decir cuál.
        $this->say(trim($list));

        return true;
    }

    /**
     * La respuesta a «¿qué opción quieres?»: una variante, varias que aún hay que distinguir, una agotada o nada de esto (false: el
     * mensaje era de otra cosa y se atiende como cualquiera).
     *
     * @param array<string,mixed> $pick
     */
    private function choose(array $pick, string $message): bool
    {
        $r = VariantPick::resolve($pick, $message);
        $name = (string) (isset($pick['n']) ? $pick['n'] : '');
        if ($r['status'] === 'none') {
            return false;
        }
        if ($r['status'] === 'one') {
            $result = $this->runner->run('cart_add', ['product_id' => (int) $pick['id'], 'variant_id' => $r['variant'], 'qty' => (int) (isset($pick['q']) ? $pick['q'] : 1)]);
            if (isset($result['error'])) {
                $this->say((string) $result['error']);
                return true;
            }
            $this->sayAdded($result, $name);
            return true;
        }
        $this->answer->pick = $r['pick'];
        foreach (VariantPick::chips($r['pick']) as $chip) {
            $this->answer->suggestions[] = $chip;
        }
        $this->say($r['status'] === 'sold_out'
            ? t('That option is sold out. Choose another one:')
            : t('«{name}» comes in several options. Which one do you want?', ['name' => $name]));
        return true;
    }

    private function unknown(): void
    {
        $this->gaveUp = true;
        $this->say(t('I did not quite understand that. I can search products, quote the shipping to your postcode, check an order and answer about returns, payment and contact.'));
        $this->answer->unanswered = true;
        $this->chips(['returns', 'payment', 'order']);
    }

    // ------------------------------------------------------------------ piezas

    private function say(string $text): void
    {
        $this->answer->text = $text;
    }

    /**
     * Las respuestas rápidas que se ofrecen. Cada una es un mensaje que este mismo asistente sabe entender.
     *
     * @param string[] $keys
     */
    private function chips(array $keys): void
    {
        $texts = [
            'returns' => t('Returns and refunds'),
            'payment' => t('Payment methods'),
            'order' => t('Where is my order?'),
            'add' => t('Add the first one to the cart'),
            'shipping' => t('Shipping costs'),
            'cart' => t('Show my cart'),
            'checkout' => t('Go to checkout'),
            'place' => t('Place my order here'),
        ];
        foreach ($keys as $key) {
            if ($key === 'add' && !self::firstBuyable($this->answer)) {
                // «Añadir el primero» solo si el primero se puede comprar: un agotado no se sugiere añadir.
                continue;
            }
            if (isset($texts[$key])) {
                $this->answer->suggestions[] = $texts[$key];
            }
        }
    }

    /** ¿Se puede comprar la primera ficha de la respuesta? (sin fichas, sí: no hay nada agotado que ofrecer). */
    public static function firstBuyable(Answer $answer): bool
    {
        return $answer->cards === [] || !isset($answer->cards[0]['status']) || $answer->cards[0]['status'] !== 'out_of_stock';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function contactLine(array $result): string
    {
        $contact = isset($result['contact']) && is_array($result['contact']) ? $result['contact'] : [];
        if (isset($contact['email'])) {
            return t('Please write to us at {email}.', ['email' => (string) $contact['email']]);
        }
        if (isset($contact['phone'])) {
            return t('Please call us at {phone}.', ['phone' => (string) $contact['phone']]);
        }

        return t('Please contact the shop.');
    }

    /**
     * @param array<string,mixed> $data lo que CustomerData sacó del mensaje
     */
    private function postcodeOf(array $data, string $n): string
    {
        if (isset($data['postcode']) && preg_match('/^\d{5}$/D', (string) $data['postcode']) === 1) {
            return (string) $data['postcode'];
        }
        if (preg_match('/\b(\d{5})\b/', $n, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /** El número de pedido que aparece en el texto: «2026-000123», o cifras detrás de «pedido» o de «#». */
    private function orderNumber(string $n): string
    {
        if (preg_match('/\b(\d{4}-\d{3,8})\b/', $n, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b(?:pedido|order|numero|num|n)\b\s*#?\s*(\d{3,10})\b/', $n, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/#\s*(\d{3,10})\b/', $n, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /** A cuál de las fichas enseñadas se refiere («el primero», «el 2», «ese»): su posición, o null. */
    /**
     * Las unidades que se piden al añadir: «3 unidades», «pon 8», «añade 2 al carrito», «8 al carrito». Esa cifra es una cantidad,
     * no «el segundo» (ecom_asistente 0.13.0: «pon 8 en el carrito» añadía una). rest = el mensaje sin la cifra.
     *
     * @return array{qty:int,rest:string}|null
     */
    public static function qtyAsked(string $n): ?array
    {
        $patterns = [
            '/\b(\d{1,2})\s*(?:unidades|unidad|uds|ud|x|piezas|units?|pieces?)\b/',
            '/\b(?:pon|ponme|ponlo|ponla|anade|anademe|anadelo|anadela|anadir|mete|metelo|metela|agrega|agregalo|agregala|add|put)\s+(\d{1,2})\b/',
            '/\b(\d{1,2})\s+(?:al|en el|a la|en la|to the|to my|in the)\s+(?:carrito|cesta|cart|basket)\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $n, $m, PREG_OFFSET_CAPTURE) === 1) {
                $qty = max(1, min(99, (int) $m[1][0]));
                $rest = substr($n, 0, (int) $m[1][1]) . substr($n, (int) $m[1][1] + strlen((string) $m[1][0]));

                return ['qty' => $qty, 'rest' => $rest];
            }
        }

        return null;
    }

    private function ordinal(string $n): ?int
    {
        if (preg_match('/\b(?:primer[oa]?|1|first|ese|esa|este|esta|it|this|that)\b/', $n) === 1) {
            return 0;
        }
        if (preg_match('/\b(?:segund[oa]|2|second)\b/', $n) === 1) {
            return 1;
        }
        if (preg_match('/\b(?:tercer[oa]?|3|third)\b/', $n) === 1) {
            return 2;
        }

        return null;
    }

    /**
     * Las fichas de la última búsqueda: id, nombre y si tienen variantes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function stateCards(): array
    {
        $cards = isset($this->state['cards']) && is_array($this->state['cards']) ? $this->state['cards'] : [];
        $out = [];
        foreach ($cards as $card) {
            if (is_array($card) && isset($card['id'], $card['n'])) {
                $out[] = $card;
            }
        }

        return $out;
    }
}
