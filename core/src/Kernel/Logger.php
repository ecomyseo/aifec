<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Registro del sitio: una línea JSON por suceso en logs/app-AAAA-MM-DD.log.
 * Nunca escribe secretos: los campos con nombre de clave, contraseña o ficha se tapan.
 */
final class Logger
{
    /** @var string */
    private $dir;

    /** @var Clock */
    private $clock;

    /** @var array<int,array<string,mixed>> lo apuntado en esta petición (pruebas y diagnóstico) */
    public $records = [];

    /** @var bool */
    private $writeFiles;

    public function __construct(string $dir, Clock $clock, bool $writeFiles = true)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
        $this->clock = $clock;
        $this->writeFiles = $writeFiles;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $record = [
            'time' => $this->clock->sql(),
            'level' => $level,
            'message' => $message,
            'context' => self::mask($context),
        ];
        $this->records[] = $record + ['channel' => $channel];
        if (!$this->writeFiles) {
            return;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $channel = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($channel));
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents(
            $this->dir . '/' . ($channel !== '' ? $channel : 'app') . '-' . gmdate('Y-m-d', $this->clock->now()) . '.log',
            $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public function exception(\Throwable $e, string $where = ''): void
    {
        $this->error(get_class($e) . ': ' . $e->getMessage(), [
            'where' => $where,
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /**
     * Tapa los valores cuyos nombres huelen a secreto.
     *
     * @param array<string|int,mixed> $data
     *
     * @return array<string|int,mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|api_?key|authorization|signature|cookie|card|cvv/i', $key) === 1) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = self::mask($value);
            } elseif (is_object($value)) {
                $out[$key] = '[' . get_class($value) . ']';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
