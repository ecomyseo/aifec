<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\View;

/**
 * Lo que una plantilla tiene a mano como $this mientras se pinta.
 *
 *   <?php $this->layout('layout', ['title' => $title]) ?>      envuelve esta plantilla en otra
 *   <?php $this->start('head') ?> … <?php $this->stop() ?>      rellena una sección del envoltorio
 *   <?= $this->section('content') ?>                            (en el envoltorio) pinta una sección
 *   <?= $this->partial('partials/product-card', ['p' => $p]) ?> pinta otra plantilla dentro
 *   <?php $this->push('scripts', '<script …>') ?>               apila algo para el final de la página
 *
 * Los datos llegan como variables sueltas. TODO lo que venga de un dato se pinta con e():
 *   <h1><?= e($product['name']) ?></h1>
 */
final class Template
{
    /** @var View */
    private $view;

    /** @var string|null */
    private $layoutName;

    /** @var array<string,mixed> */
    private $layoutData = [];

    /** @var array<string,string> */
    private $sections = [];

    /** @var string[] secciones abiertas */
    private $open = [];

    public function __construct(View $view)
    {
        $this->view = $view;
    }

    /**
     * Pinta un fichero de plantilla con sus datos y devuelve el HTML.
     *
     * @param array<string,mixed> $__data
     */
    public function run(string $__file, array $__data): string
    {
        $__level = ob_get_level();
        ob_start();
        try {
            // Motor de plantillas: aquí sí se usan las variables sueltas, es su función.
            extract($__data, EXTR_SKIP);
            include $__file;
            $content = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }
            throw $e;
        }
        if ($this->layoutName === null) {
            return $content;
        }
        $layout = $this->layoutName;
        $this->layoutName = null;
        if (!isset($this->sections['content'])) {
            $this->sections['content'] = $content;
        }

        return $this->view->renderWith($this, $layout, $this->layoutData + $__data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function layout(string $name, array $data = []): void
    {
        $this->layoutName = $name;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        $this->open[] = $section;
        ob_start();
    }

    public function stop(): void
    {
        $section = array_pop($this->open);
        if ($section === null) {
            throw new \LogicException('stop() without start() in a template');
        }
        $this->sections[$section] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return isset($this->sections[$name]) ? $this->sections[$name] : $default;
    }

    public function has(string $section): bool
    {
        return isset($this->sections[$section]) && trim($this->sections[$section]) !== '';
    }

    /**
     * @param array<string,mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        return $this->view->render($name, $data);
    }

    /** Apila un trozo (hojas de estilo, guiones) que el envoltorio pinta con stack(). */
    public function push(string $stack, string $html): void
    {
        $this->view->push($stack, $html);
    }

    public function stack(string $name): string
    {
        return $this->view->stack($name);
    }
}
