<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\RecoveryCodes;

/**
 * La ficha de una persona del equipo: lo que no es un campo del formulario.
 *
 * Quitar la verificación en dos pasos a otra persona: para el dueño que perdió el móvil y los códigos de recuperación. Quien lo hace
 * necesita el permiso de gestionar el equipo, nadie se la quita a sí mismo por este camino (para eso está «Mi cuenta», que pide
 * la contraseña), queda en el registro de actividad quién se la quitó a quién y las sesiones abiertas de esa persona se cierran.
 * Si la tienda exige el segundo paso a todo el equipo, esa persona lo tendrá que configurar de nuevo al entrar.
 */
final class UsersController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Columna derecha de la ficha (callback «side» del recurso «users»). Va DENTRO del formulario de la persona: el botón no abre
     * otro formulario, usa el mismo con otra dirección de envío (formaction) y su ficha.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }
        $on = !empty($row['totp_secret']);
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Two-step verification')) . '</h2></div><div class="af-card-body">'
            . '<p>' . ($on ? Admin::badge(t('On'), 'ok') : Admin::badge(t('Off'), 'warn')) . '</p>';
        $isMe = (int) $row['id'] === $app->auth()->adminId();
        if ($on && !$isMe && $app->auth()->can('team.edit')) {
            $html .= '<p class="af-muted af-small">' . e(t('If this person lost their phone and their recovery codes, you can turn it off so they can set it up again.')) . '</p>'
                . '<button type="submit" class="af-btn af-btn-danger" formaction="' . e($app->url()->plain('/admin/team/' . (int) $row['id'] . '/two-step/remove')) . '" formnovalidate'
                . ' data-af-confirm="' . e(t('Turn off two-step verification for this person? Their open sessions will be closed.')) . '">' . e(t('Turn off two-step verification')) . '</button>';
        } elseif ($on && $isMe) {
            $html .= '<p class="af-muted af-small">' . e(t('You manage your own in My account.')) . '</p>';
        }

        return $html . '</div></div>';
    }

    /**
     * @param array<string,string> $p id
     */
    public function removeTwoStep(Request $request, array $p): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('team.edit');
        $admin = $app->auth()->admin();
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        $target = $app->db()->row('SELECT id, email, name, totp_secret FROM {users} WHERE id = ?', [$id]);
        if ($admin === null || $target === null) {
            throw HttpError::notFound();
        }
        $back = Response::redirect(Admin::resourceUrl($app, 'users', $id), 303);
        if ((int) $admin['id'] === $id) {
            Admin::flash($app, 'bad', t('You cannot turn off your own two-step verification here. Use My account.'));

            return $back;
        }
        if (empty($target['totp_secret'])) {
            Admin::flash($app, 'warn', t('This person does not have two-step verification turned on.'));

            return $back;
        }
        $app->db()->update('users', ['totp_secret' => null, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
        (new RecoveryCodes($app->db(), $app->site()->secret()))->clear($id);
        $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => $id]);
        Admin::audit($app, 'team.two_step_removed', 'user', (string) $id, (string) $target['email'] . ' (by ' . (string) $admin['email'] . ')');
        Admin::flash($app, 'ok', t('Two-step verification turned off for {name}. Their open sessions were closed.', ['name' => (string) $target['name']]));

        return $back;
    }
}
