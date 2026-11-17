<?php
/**
 * AIFEC — pantalla del registro del sitio.
 *
 * @var array<int,array{name:string,channel:string,date:string,bytes:int}> $files
 * @var string $file
 * @var string $level
 * @var string $q
 * @var int $limit
 * @var int[] $limits
 * @var array{entries:array<int,array<string,mixed>>,bytes_read:int,size:int,complete:bool} $tail
 * @var string $downloadUrl
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Util\Bytes;

$kinds = ['error' => 'bad', 'warning' => 'warn', 'info' => 'info', 'unknown' => 'info'];

?>
<?php if ($files === []) : ?>
    <div class="af-card"><div class="af-empty"><h3><?= e(t('There are no log files yet')) ?></h3></div></div>
<?php else : ?>
    <div class="af-card">
        <div class="af-card-body">
            <form method="get" action="<?= e(app()->url()->plain('/admin/tools/logs')) ?>" class="af-toolbar">
                <select name="file" aria-label="<?= e(t('File')) ?>">
                    <?php foreach ($files as $f) : ?>
                        <option value="<?= e($f['name']) ?>"<?= $f['name'] === $file ? ' selected' : '' ?>><?= e($f['name'] . ' (' . Bytes::format($f['bytes'], 1) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="level" aria-label="<?= e(t('Level')) ?>">
                    <option value=""><?= e(t('All levels')) ?></option>
                    <?php foreach (['error' => t('Errors'), 'warning' => t('Warnings'), 'info' => t('Information')] as $value => $label) : ?>
                        <option value="<?= e($value) ?>"<?= $value === $level ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Search in the log')) ?>" aria-label="<?= e(t('Search in the log')) ?>">
                <select name="n" aria-label="<?= e(t('Lines')) ?>">
                    <?php foreach ($limits as $n) : ?>
                        <option value="<?= e($n) ?>"<?= $n === $limit ? ' selected' : '' ?>><?= e(tn('{n} line', '{n} lines', $n)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('search') ?><?= e(t('Filter')) ?></button>
                <?php if ($downloadUrl !== '') : ?>
                    <a class="af-btn" href="<?= e($downloadUrl) ?>"><?= Admin::icon('download') ?><?= e(t('Download this file')) ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="af-card">
        <?php if ($tail['entries'] === []) : ?>
            <div class="af-empty"><h3><?= e(t('Nothing matches')) ?></h3></div>
        <?php else : ?>
            <div class="af-table-wrap">
                <table class="af-table">
                    <thead>
                        <tr>
                            <th><?= e(t('Time')) ?> (UTC)</th>
                            <th><?= e(t('Level')) ?></th>
                            <th><?= e(t('Message')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tail['entries'] as $entry) : ?>
                            <tr>
                                <td class="af-nowrap af-mono af-small"><?= e($entry['time']) ?></td>
                                <td><?= Admin::badge((string) $entry['level'], isset($kinds[$entry['level']]) ? $kinds[$entry['level']] : 'info') ?></td>
                                <td>
                                    <?= e($entry['message']) ?>
                                    <?php if ($entry['context'] !== []) : ?>
                                        <details>
                                            <summary class="af-muted af-small"><?= e(t('Context')) ?></summary>
                                            <pre class="af-mono af-small"><?= e(json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$tail['complete']) : ?>
                <div class="af-card-body af-muted af-small"><?= e(t('Older lines were not read. Narrow the search or download the file.')) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
