<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Pdf\PdfWriter;

/** Solo pinta evidencia conservada. No ejecuta búsquedas, validaciones ni peticiones de IA. */
final class LearningReports
{
    public static function render(App $app, array $report, string $kind): string
    {
        if (!in_array($kind, ['experience', 'improvements'], true) || !isset($report['run']) || !is_array($report['run'])) {
            throw new \InvalidArgumentException('Unknown learning report');
        }
        $run = $report['run'];
        $pdf = new PdfWriter();
        $pdf->setMargins(15.0, 15.0, 15.0, 20.0);
        $pdf->setCreationDate(Clock::parse(isset($run['created_at']) && is_string($run['created_at']) ? $run['created_at'] : null));
        $pdf->setTitle($kind === 'experience' ? t('Simulated search experience') : t('Improvement proposals and verification'));
        $pdf->setAuthor($app->settings()->str('store.name', 'AIFEC'));
        $pdf->onFooter(static function (PdfWriter $p): void {
            $p->setY(-13.0);
            $p->setFont('Helvetica', '', 8.0);
            $p->cell(0, 5.0, t('Learning report page {page} of {pages}', ['page' => $p->pageNo(), 'pages' => '{nb}']), 'C');
        });
        $pdf->addPage();
        self::heading($pdf, $kind === 'experience' ? t('Simulated search experience') : t('Improvement proposals and verification'));
        self::line($pdf, t('Shop'), $app->settings()->str('store.name', 'AIFEC'));
        $context = isset($run['context']) && is_array($run['context']) ? $run['context'] : [];
        self::line($pdf, t('Shop URL'), $context['url'] ?? null, false);
        self::line($pdf, t('Language'), $context['lang'] ?? null);
        self::line($pdf, t('Learning round'), $run['id'] ?? null);
        self::line($pdf, t('Learning source'), LearningLabels::mode((string) ($run['mode'] ?? '')));
        self::line($pdf, t('Learning status'), LearningLabels::state((string) ($run['status'] ?? '')));
        self::paragraph($pdf, t('Profiles and evaluations in this report are simulated. Recorded query cases do not represent new visits. An AI score never certifies that the shop has passed.'));
        self::heading($pdf, t('Recorded usage and budget'));
        self::line($pdf, t('Authorised round budget'), LearningLabels::budget($app, $run));
        self::line($pdf, t('Calculated cost with configured prices'), LearningLabels::runCost($app, $run));
        self::line($pdf, t('Amount still reserved'), LearningLabels::cost($app, $run['reserved_micro'] ?? null, $run));
        self::line($pdf, t('Recorded AI calls'), $run['calls'] ?? null);
        self::line($pdf, t('Recorded input tokens'), $run['tokens_in'] ?? null);
        self::line($pdf, t('Recorded output tokens'), $run['tokens_out'] ?? null);
        self::line($pdf, t('Calls with an unknown outcome'), $run['uncertain_calls'] ?? null);
        self::line($pdf, t('Expected memory version'), $run['version'] ?? null);
        self::line($pdf, t('Applied memory version'), $run['applied_version'] ?? null);
        if (!empty($run['error'])) {
            self::line($pdf, t('Recorded problem'), LearningLabels::problem((string) $run['error']));
        }
        self::paragraph($pdf, t('Costs are calculated from recorded usage and the configured provider prices; they are not an invoice from the provider. Unknown calls keep their reservation until reviewed.'));
        if ($kind === 'experience') {
            self::experience($pdf, isset($report['items']) && is_array($report['items']) ? $report['items'] : []);
        } else {
            self::improvements($pdf, $report);
        }
        return $pdf->output();
    }

    private static function experience(PdfWriter $pdf, array $items): void
    {
        self::heading($pdf, t('Recorded search cases'));
        if ($items === []) {
            self::paragraph($pdf, t('There are no recorded cases yet. No result or success is implied.'));
        }
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            self::heading($pdf, t('Search case {number}', ['number' => $index + 1]));
            self::line($pdf, t('Query'), $item['query'] ?? null);
            self::line($pdf, t('Fictitious profile or recorded source'), LearningLabels::profile($item));
            self::line($pdf, t('Verifiable against catalogue data'), $item['verifiable'] ?? null);
            self::line($pdf, t('Expected catalogue product IDs'), $item['target_ids'] ?? null, false);
            foreach (['before' => t('Before'), 'after' => t('After')] as $key => $label) {
                self::paragraph($pdf, $label);
                $data = isset($item[$key]) && is_array($item[$key]) ? $item[$key] : [];
                self::line($pdf, t('Returned catalogue product IDs'), $data['ids'] ?? null, false);
                self::line($pdf, t('Recorded positions'), $data['positions'] ?? null, false);
                self::line($pdf, t('Total results'), $data['total'] ?? null, false);
                self::line($pdf, t('Applied deterministic filters'), $data['filters'] ?? null);
                self::line($pdf, t('Recorded deterministic score'), $data['score'] ?? null, false);
            }
            self::line($pdf, t('Recorded evaluation'), LearningLabels::verdict((string) ($item['verdict'] ?? '')));
            self::line($pdf, t('Catalogue evidence'), $item['evidence'] ?? null);
        }
    }

    private static function improvements(PdfWriter $pdf, array $report): void
    {
        self::heading($pdf, t('Pending proposals and measured checks'));
        self::paragraph($pdf, t('A proposal is not an applied improvement. Only explicit approval after deterministic validation changes the stored search memory.'));
        $proposals = isset($report['proposals']) && is_array($report['proposals']) ? $report['proposals'] : [];
        if ($proposals === []) {
            self::paragraph($pdf, t('There are no recorded proposals. No improvement is implied.'));
        }
        foreach ($proposals as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            self::heading($pdf, t('Learning proposal {number}', ['number' => $proposal['id'] ?? '']));
            self::line($pdf, t('Source query or residual phrase'), $proposal['source'] ?? null);
            self::line($pdf, t('Proposed intent or ranking'), $proposal['target'] ?? null);
            self::line($pdf, t('Proposal status'), LearningLabels::proposal((string) ($proposal['status'] ?? '')));
            self::line($pdf, t('Catalogue product IDs supporting the proposal'), $proposal['product_ids'] ?? null, false);
            self::line($pdf, t('Reason'), $proposal['reason'] ?? null);
            self::line($pdf, t('Catalogue evidence'), $proposal['evidence'] ?? null);
        }
        self::heading($pdf, t('Recorded validation'));
        $validation = isset($report['run']['validation']) && is_array($report['run']['validation']) ? LearningLabels::validation($report['run']['validation']) : [];
        if ($validation === []) {
            self::paragraph($pdf, t('No deterministic validation is recorded yet. No improvement has been certified.'));
        }
        foreach ($validation as $label => $value) {
            self::line($pdf, $label, $value, false);
        }
        $applications = isset($report['applications']) && is_array($report['applications']) ? $report['applications'] : [];
        self::heading($pdf, t('Application and reversal history'));
        if ($applications === []) {
            self::paragraph($pdf, t('No applied changes are recorded for this round.'));
        }
        foreach ($applications as $application) {
            if (!is_array($application)) {
                continue;
            }
            self::line($pdf, t('Memory version before'), $application['version_before'] ?? null);
            self::line($pdf, t('Memory version after'), $application['version_after'] ?? null);
            self::line($pdf, t('Application status'), LearningLabels::application((string) ($application['status'] ?? '')));
            self::line($pdf, t('Applied at'), $application['created_at'] ?? null);
            self::line($pdf, t('Reverted at'), $application['reverted_at'] ?? null);
        }
    }

    public static function safeText($value): string
    {
        $text = LearningLabels::value($value);
        $text = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text);
        $redacted = t('Redacted data');
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $redacted, $text);
        $text = (string) preg_replace('~https?://[^\s<>"\x27]+~iu', $redacted, $text);
        $text = (string) preg_replace('/\b(?:sk-[A-Za-z0-9_-]{8,}|AIza[A-Za-z0-9_-]{12,}|Bearer\s+[A-Za-z0-9._-]{8,})\b/u', $redacted, $text);
        $text = (string) preg_replace('/(?<![\pL\pN])\+?\d(?:[\s().-]*\d){7,}(?![\pL\pN])/u', $redacted, $text);
        return mb_substr($text, 0, 2400, 'UTF-8');
    }

    private static function heading(PdfWriter $pdf, string $text): void
    {
        $pdf->ln(3.0);
        $pdf->setFont('Helvetica', 'B', 12.0);
        $pdf->multiCell(0, 6.0, $text);
    }

    private static function paragraph(PdfWriter $pdf, string $text): void
    {
        $pdf->setFont('Helvetica', '', 9.0);
        $pdf->multiCell(0, 4.5, $text);
    }

    private static function line(PdfWriter $pdf, string $label, $value, bool $redact = true): void
    {
        self::paragraph($pdf, $label . ': ' . ($redact ? self::safeText($value) : LearningLabels::value($value)));
    }
}
