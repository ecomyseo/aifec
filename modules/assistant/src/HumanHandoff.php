<?php
/** AIFEC — relevo humano con resumen visible y aceptación expresa. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class HumanHandoff
{
    public static function handle(App $app, array $conversation, int $messageId, string $message, array $state): ?Answer
    {
        $n = RuleBrain::norm($message);
        $status = (string) $conversation['handoff_status'];
        $id = (int) $conversation['id'];
        $answer = new Answer();
        $share = t('Share this summary with the team');
        $poll = t('Check the team’s reply');
        $decline = t('I would rather continue here');
        $problem = preg_match('/\b(?:no (?:me )?ha llegado|pedido (?:retrasado|perdido)|order is late|order has not arrived)\b/', $n) === 1;
        $wantsPerson = preg_match('/\b(?:hablar con (?:una |un )?(?:persona|humano|agente)|speak to (?:a |an )?(?:person|human|agent)|no me (?:estas )?ayuda(?:s|ndo)|not helping)\b/', $n) === 1;

        if ($status === 'offered' && $n === RuleBrain::norm($decline)) {
            $app->db()->run("UPDATE {conversations} SET handoff_status = '', handoff_summary = NULL WHERE id = ? AND handoff_status = 'offered'", [$id]);
            $answer->text = t('We can continue here.');
        } elseif ($status === 'offered' && $n === RuleBrain::norm($share)) {
            $app->db()->run("UPDATE {conversations} SET handoff_status = 'pending' WHERE id = ? AND handoff_status = 'offered' AND handoff_request = ?", [$id, (int) $conversation['handoff_request']]);
            $answer->text = t('Your request is with the shop team. You can check their reply here.');
            $answer->suggestions = [$poll];
        } elseif (in_array($status, ['pending', 'replied'], true) && ($n === RuleBrain::norm($poll) || $wantsPerson || $problem)) {
            $answer->text = $status === 'replied'
                ? t('Reply from the shop team:') . "\n" . (string) $conversation['handoff_reply']
                : t('The team has not replied yet.');
            if ($status === 'pending') { $answer->suggestions = [$poll]; }
            $answer->actions[] = ['type' => 'private_turn'];
        } elseif ($wantsPerson || $problem) {
            $summary = $problem ? t('The customer has a question about a delivery.') : t('The customer would like personal assistance.');
            if ($problem && preg_match('/\b\d{4}-\d{3,8}\b/', $message, $ref)) {
                $summary .= ' ' . t('Reference supplied by the customer: {number}.', ['number' => $ref[0]]);
            }
            $products = array_slice(array_filter(array_column($state['cards'] ?? [], 'n')), 0, 3);
            if ($products) { $summary .= ' ' . t('Products discussed: {products}.', ['products' => implode(', ', $products)]); }
            $contact = (new ShopTools($app))->storeInfo(['topic' => 'contact'], []);
            $lines = [];
            if ($problem || strpos($n, 'ayud') !== false || strpos($n, 'helping') !== false) {
                $lines[] = t('I am sorry this has been frustrating.');
            }
            foreach (['email' => 'Email: {value}', 'phone' => 'Phone: {value}'] as $key => $label) {
                if (!empty($contact['contact'][$key])) { $lines[] = t($label, ['value' => $contact['contact'][$key]]); }
            }
            if (!empty($contact['contact']['whatsapp'])) { $lines[] = '[WhatsApp](' . $contact['contact']['whatsapp'] . ')'; }
            $hours = trim($app->settings()->str('assistant.hours'));
            if ($hours !== '') { $lines[] = t('Our opening hours: {hours}', ['hours' => $hours]); }
            $lines[] = t('Would you like to share this summary with the shop team?');
            $lines[] = $summary;
            $app->db()->update('conversations', ['handoff_status' => 'offered', 'handoff_summary' => $summary, 'handoff_request' => $messageId, 'handoff_reply' => null], ['id' => $id]);
            $answer->text = implode("\n", $lines);
            $answer->suggestions = [$share, $decline];
        } else {
            return null;
        }
        return $answer;
    }
}
