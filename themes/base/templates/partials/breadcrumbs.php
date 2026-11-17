<?php
/**
 * AIFEC — migas de pan (el HTML accesible lo da la función breadcrumbs()).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{name:string,url:string}> $crumbs
 */

echo breadcrumbs(isset($crumbs) && is_array($crumbs) ? $crumbs : []);
