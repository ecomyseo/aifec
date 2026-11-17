<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

/**
 * El servicio «semantic» completo: lo que ya ofrecía (datos estructurados y versiones en texto) más la auditoría AIFEC.
 *
 *   audit()            la nota del sitio (0 a 100) y sus comprobaciones;
 *   auditProduct($id)  la nota de un producto y lo que le falta, con el enlace donde se arregla.
 *
 * Cada comprobación es ['id', 'ok', 'label', 'fix_url', 'weight'].
 */
final class AuditService extends Semantic
{
    /**
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}
     */
    public function audit(): array
    {
        return Audit::site($this);
    }

    /**
     * @return array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>}
     */
    public function auditProduct(int $id): array
    {
        return Audit::product($this, $id);
    }
}
