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

?><p style="margin:0 0 16px 0;"><?= e(t('Hello {name}', ['name' => $name])) ?></p>
<p style="margin:0 0 16px 0;"><?= e(t('These are the legal texts that apply to your order {number}. Keep this email.', ['number' => $number])) ?></p>
<?php foreach ($blocks as $af_block) : ?>
<p style="margin:0 0 6px 0;font-weight:bold;"><?= e($af_block['title']) ?></p>
<?php foreach ($af_block['paragraphs'] as $af_line) : ?>
<p style="margin:0 0 10px 0;"><?= e($af_line) ?></p>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($waiver === 'yes') : ?>
<p style="margin:16px 0 8px 0;"><?= e(t('You asked for the download to start now and you accepted that you lose your right of withdrawal once it starts.')) ?></p>
<?php elseif ($waiver === 'no') : ?>
<p style="margin:16px 0 8px 0;"><?= e(t('You did not waive your right of withdrawal, so you keep it.')) ?></p>
<?php endif; ?>
