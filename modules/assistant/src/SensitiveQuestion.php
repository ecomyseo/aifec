<?php
/**
 * AIFEC — identifica consultas de salud antes de guardar el mensaje o consultar al proveedor.
 * @author Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

final class SensitiveQuestion
{
    public static function matches(string $message): bool
    {
        $text = RuleBrain::norm($message);
        $personal = '/\b(?:embaraz\w*|pregnan\w*|lactancia|amamant\w*|breastfeed\w*|alergi\w*|alergic\w*|allerg\w*|medicacion|medicamentos?|medication|enfermedad|diabet\w*|asma|asthma|eczema|dermatitis|tratamiento medico|medical condition)\b/';
        if (preg_match($personal, $text) === 1) {
            return true;
        }
        $minor = preg_match('/\b(?:menor(?:es)?|bebes?|ninos?|ninas?|children|child|baby|infant|toddler|years? old|anos de edad)\b/', $text);
        return $minor === 1 && preg_match('/\b(?:usar|aplicar|tomar|ingerir|piel|dosis|segur\w*|crema|serum|use|take|cream|skin|dosage|safe)\b/', $text) === 1;
    }

    /** Solo devuelve advertencias expresas de la ficha pública referida por enlace, página o nombre exacto. */
    public static function catalogueNotice(\Aifec\Kernel\App $app, string $message, int $pageId): string
    {
        $id = 0;
        if (preg_match('/[«"]([^"»]{2,120})[»"]/u', $message, $name)) {
            $matches = $app->db()->column("SELECT DISTINCT p.id FROM {products} p JOIN {product_i18n} i ON i.product_id = p.id WHERE i.name = ? AND p.status = 'published' LIMIT 2", [trim($name[1])]);
            if (!$matches) { return t('I could not find a published product with that exact name.'); }
            if (count($matches) !== 1) { return ''; }
            $id = (int) $matches[0];
        } elseif ($pageId > 0 && preg_match('/\b(?:est[ea]|this) (?:articulo|producto|crema|serum|product|cream|item)\b/', RuleBrain::norm($message))) {
            $id = $pageId;
        }
        if (!$id) { return ''; }
        $product = $app->service('catalog')->product($id);
        if (!$product) { return ''; }
        $warnings = [];
        foreach (array_merge($product['facts'] ?? [], $product['attributes'] ?? []) as $fact) {
            $key = RuleBrain::norm((string) ($fact['label'] ?? $fact['name'] ?? ''));
            if (!in_array($key, ['advertencia', 'advertencias', 'precauciones', 'contraindicaciones', 'warning', 'warnings', 'precautions', 'contraindications'], true)) { continue; }
            $value = trim(strip_tags((string) ($fact['value'] ?? '')));
            if ($value !== '' && mb_strlen($value) <= 500) { $warnings[] = $value; }
        }
        return $warnings ? t('Product page warning: {warning}', ['warning' => implode(' ', array_unique($warnings))]) : '';
    }

    /** Conserva por separado cada línea publicable de una respuesta comercial. */
    public static function withoutClaims(string $text): string
    {
        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $claim = '/\b(?:cura(?:n|ra)?|curar|previene(?:n)?|prevenir|alivia(?:n)?|aliviar|trata(?:n)?|tratar|elimina(?:n)?|cures?|heals?|treats?|prevents?|relieves?|eliminates?)\s+(?:(?:por completo|permanentemente|el|la|los|las|tu|su|your|the|chronic|severe)\s+)*(?:acne|eccema|eczema|dermatitis|psoriasis|dolor|pain|artritis|arthritis|diabetes|inflamacion|inflammation|enfermedad|disease|ansiedad|anxiety|infeccion|infection|cancer)\b/';
            if (preg_match($claim, RuleBrain::norm($line)) !== 1) {
                $kept[] = $line;
            }
        }
        return trim(implode("\n", $kept));
    }
}
