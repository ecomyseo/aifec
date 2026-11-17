<?php
/**
 * AIFEC — iconos del panel: trazos sencillos en SVG, dibujados en una rejilla de 24 × 24.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

final class Icons
{
    /** @var array<string,string> nombre => interior del SVG */
    private const PATHS = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M10 20v-6h4v6"/>',
        'cart' => '<path d="M3 4h2.5l2.2 11h10.6l2-8H7"/><circle cx="9.5" cy="19" r="1.4"/><circle cx="17" cy="19" r="1.4"/>',
        'box' => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5"/><path d="M12 12v9"/>',
        'tag' => '<path d="M3 12.5V4h8.5l9.5 9.5-8.5 8.5z"/><circle cx="7.5" cy="8.5" r="1.4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.8a3.5 3.5 0 0 1 0 6.4"/><path d="M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.2 4-6.5 8-6.5s7.2 2.3 8 6.5"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
        'image' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="m4 18 5.5-5.5 4 4 2.5-2.5 4 4"/>',
        'megaphone' => '<path d="M4 10v4h3l8 4.5v-13L7 10z"/><path d="M18.5 9a4.5 4.5 0 0 1 0 6"/><path d="M7.5 14.5 9 20h2.5"/>',
        'sparkles' => '<path d="M10 3.5 11.8 9l5.2 1.8-5.2 1.8L10 18l-1.8-5.4L3 10.8 8.2 9z"/><path d="M18.5 3v4M16.5 5h4M18.5 16v4M16.5 18h4"/>',
        'palette' => '<path d="M12 3a9 9 0 1 0 0 18c1.6 0 2-1 1.4-2.2-.7-1.5.2-2.8 1.8-2.8H18a3 3 0 0 0 3-3c0-5.5-4-10-9-10z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="10" cy="7.5" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'plug' => '<path d="M9 3v5M15 3v5"/><path d="M6.5 8h11v3.5a5.5 5.5 0 0 1-11 0z"/><path d="M12 17v4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'tool' => '<path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L3.5 17.5l3 3 5.7-5.7a4 4 0 0 0 5.3-5.3l-2.7 2.700-2.300-.7-.7-2.300z"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-7"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.500 4.500"/>',
        'filter' => '<path d="M4 5h16l-6 7.500V19l-4-2v-4.500z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.500 6.500 4 4"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6.500 7l1 13h9l1-13"/><path d="M10 11v6M14 11v6"/>',
        'eye' => '<path d="M2.500 12S6 5.500 12 5.500 21.500 12 21.500 12 18 18.500 12 18.500 2.500 12 2.500 12z"/><circle cx="12" cy="12" r="3"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>',
        'menu' => '<path d="M4 6.500h16M4 12h16M4 17.500h16"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.500v2.500M12 19v2.500M2.500 12H5M19 12h2.500M5.300 5.300 7 7M17 17l1.700 1.700M5.300 18.700 7 17M17 7l1.700-1.700"/>',
        'moon' => '<path d="M20 14.500A8.500 8.500 0 0 1 9.500 4a8.500 8.500 0 1 0 10.500 10.500z"/>',
        'logout' => '<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4"/><path d="M9 12h10"/>',
        'check' => '<path d="m5 12.500 4.500 4.500L19 7.500"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'alert' => '<path d="M12 3.500 22 20H2z"/><path d="M12 10v4.500M12 17.200v.300"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.300v.300"/>',
        'chevron' => '<path d="m7 10 5 5 5-5"/>',
        'drag' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/>',
        'download' => '<path d="M12 4v11"/><path d="m7.500 11 4.500 4.500 4.500-4.500"/><path d="M4 20h16"/>',
        'upload' => '<path d="M12 16V5"/><path d="m7.500 9 4.500-4.500L16.500 9"/><path d="M4 20h16"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.500 7 8.500 6.500L20.500 7"/>',
        'truck' => '<path d="M2.500 6h11v10h-11z"/><path d="M13.500 9.500h4l3.500 3.500v3h-7.500z"/><circle cx="7" cy="17.500" r="1.800"/><circle cx="17" cy="17.500" r="1.800"/>',
        'card' => '<rect x="2.500" y="5" width="19" height="14" rx="2"/><path d="M2.500 10h19"/><path d="M6.500 15h4"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c3 3.200 3 14.800 0 18M12 3c-3 3.200-3 14.800 0 18"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12.500 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'robot' => '<rect x="4.500" y="8" width="15" height="11" rx="2.500"/><path d="M12 8V4.500"/><circle cx="12" cy="3.500" r="1"/><circle cx="9" cy="13" r="1.200"/><circle cx="15" cy="13" r="1.200"/><path d="M9.500 16.500h5"/><path d="M2.500 12v3M21.500 12v3"/>',
        'lock' => '<rect x="5" y="10.500" width="14" height="10" rx="2"/><path d="M8 10.500V7.500a4 4 0 0 1 8 0v3"/>',
        'refresh' => '<path d="M20 12a8 8 0 0 1-14.500 4.600"/><path d="M4 12a8 8 0 0 1 14.500-4.600"/><path d="M18.500 3v4.500H14"/><path d="M5.500 21v-4.500H10"/>',
        'list' => '<path d="M8.500 6.500H20M8.500 12H20M8.500 17.500H20"/><path d="M4 6.500h.500M4 12h.500M4 17.500h.500"/>',
        'star' => '<path d="m12 3.500 2.600 5.400 5.900.800-4.300 4.100 1 5.900L12 16.900l-5.200 2.800 1-5.900L3.500 9.700l5.900-.800z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.500l3.500 2"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.500"/><circle cx="17" cy="17" r="2.500"/>',
        'folder' => '<path d="M3 6.500h6l2 2.500h10v10.500H3z"/>',
        'code' => '<path d="m8.500 7.500-5 4.500 5 4.500M15.500 7.500l5 4.500-5 4.500"/><path d="m13.500 5-3 14"/>',
        'book' => '<path d="M4.500 4.500h6a3 3 0 0 1 1.500 2.600V20a3 3 0 0 0-1.500-1H4.500z"/><path d="M19.500 4.500h-6A3 3 0 0 0 12 7.100V20a3 3 0 0 1 1.500-1h6z"/>',
        'shield' => '<path d="M12 3 4.500 6v5.500c0 4.500 3 8 7.500 9.500 4.500-1.500 7.500-5 7.500-9.500V6z"/><path d="m9 12 2.200 2.200L15.500 10"/>',
        'bolt' => '<path d="M13 2.500 5 13.500h6l-1 8 8-11h-6z"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'chat' => '<path d="M4 5h16v11H9.500L5 20v-4H4z"/><path d="M8 9.500h8M8 12.500h5"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1"/><path d="M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7.500" ry="3"/><path d="M4.500 6v6c0 1.700 3.400 3 7.500 3s7.500-1.300 7.500-3V6"/><path d="M4.500 12v6c0 1.700 3.400 3 7.500 3s7.500-1.300 7.500-3v-6"/>',
        'dot' => '<circle cx="12" cy="12" r="3"/>',
    ];

    /** Icono listo para pintar. Un nombre desconocido da un punto: nunca rompe la página. */
    public static function svg(string $name, string $class = 'af-ico'): string
    {
        $inner = isset(self::PATHS[$name]) ? self::PATHS[$name] : self::PATHS['dot'];

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }
}
