<?php
/**
 * AIFEC — GM legal texts per product: correo «legal_notice», los textos legales de lo que ha comprado (soporte duradero).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                                                $name
 * @var string                                                                $number
 * @var array<int,array{id:int,title:string,kind:string,paragraphs:string[]}> $blocks
 * @var string                                                                $waiver  yes, no o '' (no se preguntó)
 */

declare(strict_types=1);

$blocks = isset($blocks) && is_array($blocks) ? $blocks : [];
$waiver = isset($waiver) ? (string) $waiver : '';
$waiver_lines = isset($waiver_lines) && is_array($waiver_lines) ? $waiver_lines : [];

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('These are the legal texts that apply to your order {number}. Keep this email.', ['number' => $number])) ?></p>
<?php foreach ($blocks as $af_block) : ?>
<p style="margin:0 0 6px 0;font-weight:bold;"><?= e($af_block['title']) ?></p>
<?php foreach ($af_block['paragraphs'] as $af_line) : ?>
<p style="margin:0 0 10px 0;"><?= e($af_line) ?></p>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($waiver_lines !== []) : ?>
<p style="margin:16px 0 6px 0;font-weight:bold;"><?= e(t('Right of withdrawal')) ?></p>
<?php foreach ($waiver_lines as $af_line) : ?>
<p style="margin:0 0 10px 0;"><?= e($af_line) ?></p>
<?php endforeach; ?>
<?php endif; ?>
