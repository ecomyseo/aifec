<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Mail;

/**
 * Mensaje de correo (RFC 5322 / 2045): se compone con una interfaz fluida y se serializa con toString().
 *
 * - Cabeceras con caracteres no ASCII: palabras codificadas «=?UTF-8?B?…?=» de 75 caracteres como máximo.
 * - Cuerpos de texto y HTML en UTF-8 con quoted-printable; adjuntos e imágenes en línea en base64 (líneas de 76).
 * - Ningún dato de entrada puede llevar CR, LF ni otros caracteres de control (inyección de cabeceras).
 * - Todo el mensaje usa CRLF.
 */
final class MimeMessage
{
    /** Cabeceras que gestiona la propia clase: no se pueden fijar con header(). */
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding', 'content-disposition', 'content-id',
    ];

    /**
     * Bytes crudos por palabra codificada: 36 bytes son 48 caracteres base64 y, con el marco «=?UTF-8?B?…?=», 60 en total.
     * Cada palabra queda muy por debajo del máximo de 75 y la primera línea cabe tras «Subject: » en las 78 columnas.
     */
    private const WORD_BYTES = 36;

    /** @var array{email:string,name:string}|null */
    private ?array $from = null;

    /** @var array<int,array{email:string,name:string}> */
    private array $to = [];

    /** @var array<int,array{email:string,name:string}> */
    private array $cc = [];

    /** @var array<int,array{email:string,name:string}> */
    private array $bcc = [];

    /** @var array<int,array{email:string,name:string}> */
    private array $replyTo = [];

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var array<int,array{filename:string,content:string,mime:string}> */
    private array $attachments = [];

    /** @var array<int,array{cid:string,content:string,mime:string}> */
    private array $embeds = [];

    /** @var array<int,array{0:string,1:string}> */
    private array $headers = [];

    private ?int $timestamp = null;

    private ?string $messageId = null;

    public function from(string $email, string $name = ''): self
    {
        $this->from = $this->address($email, $name);

        return $this;
    }

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = $this->address($email, $name);

        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = $this->address($email, $name);

        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = $this->address($email, $name);

        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo[] = $this->address($email, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->assertSingleLine($subject, 'subject');
        $this->subject = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function attach(string $filename, string $content, string $mime = 'application/octet-stream'): self
    {
        $this->assertSingleLine($filename, 'attachment file name');
        if ($filename === '') {
            throw new MailException('The attachment file name is empty');
        }
        $this->assertMime($mime);
        $this->attachments[] = ['filename' => $filename, 'content' => $content, 'mime' => $mime];

        return $this;
    }

    /** Imagen en línea: el HTML la referencia con src="cid:$cid". */
    public function embed(string $cid, string $content, string $mime): self
    {
        if (preg_match('/^[^\s<>\x00-\x1F\x7F]+$/', $cid) !== 1) {
            throw new MailException('Invalid Content-ID');
        }
        $this->assertMime($mime);
        $this->embeds[] = ['cid' => $cid, 'content' => $content, 'mime' => $mime];

        return $this;
    }

    /** Cabecera propia (X-…, List-Unsubscribe…). Las que gestiona la clase no se pueden fijar. */
    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name) !== 1) {
            throw new MailException('Invalid header name');
        }
        if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new MailException('The header ' . $name . ' is managed by the message and cannot be set');
        }
        $this->assertSingleLine($value, 'header value');
        $this->headers[] = [$name, $value];

        return $this;
    }

    /** Fija el instante de la cabecera Date (para pruebas y reenvíos). */
    public function date(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /** Fija el Message-ID (con o sin los ángulos). */
    public function messageId(string $id): self
    {
        $id = trim($id);
        $id = trim($id, '<>');
        if ($id === '' || preg_match('/^[^\s<>\x00-\x1F\x7F]+@[^\s<>\x00-\x1F\x7F]+$/', $id) !== 1) {
            throw new MailException('Invalid Message-ID');
        }
        $this->messageId = '<' . $id . '>';

        return $this;
    }

    /** @return string[] direcciones de to, cc y bcc, sin repetir (sin distinguir mayúsculas) */
    public function recipients(): array
    {
        $seen = [];
        $out = [];
        foreach ([$this->to, $this->cc, $this->bcc] as $list) {
            foreach ($list as $a) {
                $key = strtolower($a['email']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $a['email'];
                }
            }
        }

        return $out;
    }

    /** Dirección del remitente (la del sobre: MAIL FROM). */
    public function sender(): string
    {
        if ($this->from === null) {
            throw new MailException('The message has no sender');
        }

        return $this->from['email'];
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /** El mensaje completo (cabeceras + cuerpo) con CRLF, sin la cabecera Bcc. */
    public function toString(): string
    {
        $parts = $this->parts(false);
        $out = '';
        foreach ($parts['headers'] as $h) {
            $out .= $h[0] . ': ' . $h[1] . "\r\n";
        }

        return $out . "\r\n" . $parts['body'];
    }

    /**
     * El mensaje por separado, para los transportes que necesitan las cabeceras aparte (la función mail()).
     * Cada cabecera es [nombre, valor ya plegado y codificado].
     *
     * @return array{headers:array<int,array{0:string,1:string}>,body:string}
     */
    public function parts(bool $includeBcc = false): array
    {
        if ($this->from === null) {
            throw new MailException('The message has no sender');
        }
        $entity = $this->buildEntity();

        $h = [];
        $h[] = ['Date', $this->dateHeader()];
        $h[] = ['From', $this->formatAddress($this->from)];
        if ($this->to !== []) {
            $h[] = ['To', $this->addressList($this->to)];
        }
        if ($this->cc !== []) {
            $h[] = ['Cc', $this->addressList($this->cc)];
        }
        if ($includeBcc && $this->bcc !== []) {
            $h[] = ['Bcc', $this->addressList($this->bcc)];
        }
        if ($this->replyTo !== []) {
            $h[] = ['Reply-To', $this->addressList($this->replyTo)];
        }
        $h[] = ['Subject', $this->unstructured('Subject', $this->subject)];
        $h[] = ['Message-ID', $this->resolveMessageId()];
        $h[] = ['MIME-Version', '1.0'];
        foreach ($this->headers as $custom) {
            $h[] = [$custom[0], $this->unstructured($custom[0], $custom[1])];
        }
        foreach ($entity['headers'] as $eh) {
            $h[] = $eh;
        }

        return ['headers' => $h, 'body' => $entity['body']];
    }

    // ---------------------------------------------------------------- estructura MIME

    /** @return array{headers:array<int,array{0:string,1:string}>,body:string} */
    private function buildEntity(): array
    {
        $text = $this->text;
        if ($text === null) {
            $text = $this->html !== null ? $this->htmlToText($this->html) : '';
        }
        $textPart = $this->textEntity('text/plain', $text);

        if ($this->html !== null) {
            $htmlPart = $this->textEntity('text/html', $this->html);
            if ($this->embeds !== []) {
                $htmlPart = $this->multipart('related', array_merge([$htmlPart], $this->embedEntities()));
            }
            $main = $this->multipart('alternative', [$textPart, $htmlPart]);
        } elseif ($this->embeds !== []) {
            $main = $this->multipart('related', array_merge([$textPart], $this->embedEntities()));
        } else {
            $main = $textPart;
        }

        if ($this->attachments !== []) {
            $entities = [$main];
            foreach ($this->attachments as $a) {
                $entities[] = [
                    'headers' => [
                        ['Content-Type', $this->paramHeader($a['mime'], 'name', $a['filename'])],
                        ['Content-Transfer-Encoding', 'base64'],
                        ['Content-Disposition', $this->paramHeader('attachment', 'filename', $a['filename'])],
                    ],
                    'body' => $this->base64Body($a['content']),
                ];
            }
            $main = $this->multipart('mixed', $entities);
        }

        return $main;
    }

    /** @return array<int,array{headers:array<int,array{0:string,1:string}>,body:string}> */
    private function embedEntities(): array
    {
        $out = [];
        foreach ($this->embeds as $e) {
            $out[] = [
                'headers' => [
                    ['Content-Type', $e['mime']],
                    ['Content-Transfer-Encoding', 'base64'],
                    ['Content-ID', '<' . $e['cid'] . '>'],
                    ['Content-Disposition', 'inline'],
                ],
                'body' => $this->base64Body($e['content']),
            ];
        }

        return $out;
    }

    /** @return array{headers:array<int,array{0:string,1:string}>,body:string} */
    private function textEntity(string $type, string $content): array
    {
        $content = $this->normalizeNewlines($content);
        if ($content !== '' && substr($content, -2) !== "\r\n") {
            // Un espacio final sin salto detrás no se codifica en quoted-printable y el transporte lo perdería.
            $content .= "\r\n";
        }

        return [
            'headers' => [
                ['Content-Type', $type . '; charset=UTF-8'],
                ['Content-Transfer-Encoding', 'quoted-printable'],
            ],
            'body' => quoted_printable_encode($content),
        ];
    }

    /**
     * @param array<int,array{headers:array<int,array{0:string,1:string}>,body:string}> $entities
     *
     * @return array{headers:array<int,array{0:string,1:string}>,body:string}
     */
    private function multipart(string $subtype, array $entities): array
    {
        $boundary = 'aifec_' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($entities as $entity) {
            $body .= '--' . $boundary . "\r\n" . $this->serialize($entity) . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        return [
            'headers' => [['Content-Type', 'multipart/' . $subtype . '; boundary="' . $boundary . '"']],
            'body' => $body,
        ];
    }

    /** @param array{headers:array<int,array{0:string,1:string}>,body:string} $entity */
    private function serialize(array $entity): string
    {
        $out = '';
        foreach ($entity['headers'] as $h) {
            $out .= $h[0] . ': ' . $h[1] . "\r\n";
        }

        return $out . "\r\n" . $entity['body'];
    }

    private function base64Body(string $content): string
    {
        return rtrim(chunk_split(base64_encode($content), 76, "\r\n"), "\r\n");
    }

    private function normalizeNewlines(string $text): string
    {
        $n = preg_replace('/\r\n|\r|\n/', "\r\n", $text);

        return $n === null ? $text : $n;
    }

    /** Valor de Content-Type / Content-Disposition con un parámetro de nombre de fichero (RFC 2231 si no es ASCII). */
    private function paramHeader(string $value, string $key, string $filename): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $filename) === 1) {
            return $value . '; ' . $key . '="' . addcslashes($filename, '"\\') . '"';
        }
        $fallback = preg_replace('/[^\x20-\x7E]/u', '_', $filename);
        if ($fallback === null) {
            throw new MailException('The file name is not valid UTF-8');
        }

        return $value . ";\r\n " . $key . '="' . addcslashes($fallback, '"\\') . "\";\r\n "
            . $key . "*=UTF-8''" . rawurlencode($filename);
    }

    // ---------------------------------------------------------------- texto de la versión sin HTML

    private function htmlToText(string $html): string
    {
        $t = $this->rx('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);
        $t = $this->rxCallback('#<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function (array $m): string {
            $label = trim(strip_tags($m[3]));
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $url === '' || $url[0] === '#' || strncasecmp($url, 'mailto:', 7) === 0 || $label === $url) {
                return $m[3];
            }

            return $m[3] . ' (' . $url . ')';
        }, $t);
        $t = $this->rx('#<br\s*/?>#i', "\n", $t);
        $t = $this->rx('#<li\b[^>]*>#i', "\n- ", $t);
        $t = $this->rx('#</(p|div|h[1-6]|li|tr|table|ul|ol|blockquote|section|article|header|footer)>#i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = $this->rx('/[ \t]+/', ' ', $t);
        $t = $this->rx('/ ?\n ?/', "\n", $t);
        $t = $this->rx('/\n{3,}/', "\n\n", $t);

        return trim($t);
    }

    private function rx(string $pattern, string $replacement, string $subject): string
    {
        $r = preg_replace($pattern, $replacement, $subject);

        return $r === null ? $subject : $r;
    }

    private function rxCallback(string $pattern, callable $callback, string $subject): string
    {
        $r = preg_replace_callback($pattern, $callback, $subject);

        return $r === null ? $subject : $r;
    }

    // ---------------------------------------------------------------- cabeceras

    /** @return array{email:string,name:string} */
    private function address(string $email, string $name): array
    {
        $this->assertSingleLine($email, 'email address');
        $this->assertSingleLine($name, 'address name');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('Invalid email address: ' . $email);
        }

        return ['email' => $email, 'name' => $name];
    }

    /** @param array<int,array{email:string,name:string}> $list */
    private function addressList(array $list): string
    {
        $items = [];
        foreach ($list as $a) {
            $items[] = $this->formatAddress($a);
        }

        return implode(",\r\n ", $items);
    }

    /** @param array{email:string,name:string} $a */
    private function formatAddress(array $a): string
    {
        if ($a['name'] === '') {
            return $a['email'];
        }

        return $this->displayName($a['name']) . ' <' . $a['email'] . '>';
    }

    private function displayName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            return $this->encodeWords($name);
        }
        if (preg_match('/^[A-Za-z0-9 !#$%&\'*+\/=?^_`{|}~-]+$/', $name) === 1) {
            return $name;
        }

        return '"' . addcslashes($name, '"\\') . '"';
    }

    /** Valor de una cabecera de texto libre: codificado si no es ASCII y plegado a unos 76 caracteres si no. */
    private function unstructured(string $name, string $value): string
    {
        if (preg_match('/[^\x20-\x7E\t]/', $value) === 1) {
            return $this->encodeWords($value);
        }
        $line = $name . ': ' . $value;
        if (strlen($line) <= 76 || strpos($value, ' ') === false) {
            return $value;
        }
        $prefix = $name . ':';
        $current = $prefix;
        $lines = [];
        foreach (explode(' ', $value) as $word) {
            if ($word === '') {
                continue;
            }
            if ($current !== $prefix && strlen($current) + 1 + strlen($word) > 76) {
                $lines[] = $current;
                $current = ' ' . $word;
            } else {
                $current .= ' ' . $word;
            }
        }
        $lines[] = $current;

        // La primera línea lleva «Nombre:» delante, que pone quien serializa: se quita.
        $folded = implode("\r\n", $lines);

        return ltrim(substr($folded, strlen($prefix)));
    }

    /** Palabras codificadas «=?UTF-8?B?…?=» separadas por «CRLF + espacio», sin partir ningún carácter. */
    private function encodeWords(string $text): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new MailException('Header text is not valid UTF-8');
        }
        $words = [];
        $current = '';
        foreach ($chars as $char) {
            if ($current !== '' && strlen($current) + strlen($char) > self::WORD_BYTES) {
                $words[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        if ($current !== '') {
            $words[] = $current;
        }

        $encoded = [];
        foreach ($words as $w) {
            $encoded[] = '=?UTF-8?B?' . base64_encode($w) . '?=';
        }

        return implode("\r\n ", $encoded);
    }

    private function dateHeader(): string
    {
        // Único punto donde se lee el reloj: sin date() fijado, el instante en que se compone el mensaje.
        $ts = $this->timestamp !== null ? $this->timestamp : time();

        return gmdate('D, d M Y H:i:s', $ts) . ' +0000';
    }

    private function resolveMessageId(): string
    {
        if ($this->messageId === null) {
            $email = $this->sender();
            $at = strrpos($email, '@');
            $domain = $at === false ? 'localhost' : substr($email, $at + 1);
            $this->messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        }

        return $this->messageId;
    }

    private function assertSingleLine(string $value, string $what): void
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new MailException('Control characters are not allowed in the ' . $what);
        }
    }

    private function assertMime(string $mime): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/', $mime) !== 1) {
            throw new MailException('Invalid MIME type');
        }
    }
}
