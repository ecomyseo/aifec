<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Kernel\Site;
use Aifec\Kernel\SiteResolver;
use Aifec\Security\Crypto;

/**
 * Las personas administradoras del sitio maestro son las DUEÑAS de la instalación: entran en el panel de cada demostración
 * con su misma cuenta (su correo, su contraseña y su segundo paso) y con el papel de administrador, sin límites.
 *
 * La persona «demo» de cada demostración tiene el papel recortado a propósito (su contraseña es pública): con ella faltan
 * menús y hay pantallas que contestan «sin permiso». Quien monta la instalación necesita verlo todo, y no con una cuenta
 * pública. (Lo pidió el usuario el 13/11/2026: «da errores de permiso y no salen todos los menús».)
 *
 * Cómo: el maestro copia a la tabla de personas de cada demostración a sus administradores activos. De cada uno viajan el
 * correo, el nombre, el idioma y el RESUMEN de la contraseña (nunca una contraseña: el maestro tampoco la tiene). El secreto
 * del segundo paso se descifra con la clave del maestro y se vuelve a cifrar con la de la demostración; si no se puede, esa
 * persona NO se copia (no se abre una puerta con solo contraseña a quien exige segundo paso). Los códigos de recuperación no
 * viajan.
 *
 * Solo demostraciones: un sitio que no lo es (la tienda de un cliente en la misma instalación) no se toca. Quien deja de
 * ser administrador del maestro pierde el acceso en la siguiente puesta al día: se desactiva la copia que este mismo
 * mecanismo creó (se reconoce por la marca del ajuste «sites.owners» de la demostración), nunca una cuenta ajena.
 *
 * Se pone al día al construir o restablecer una demostración, al apuntar una, cada noche, al pulsar «Abrir panel» en
 * «Sitios y demostraciones» y con la orden `sites:owners`.
 */
final class Owners
{
    /** Ajuste DE LA DEMOSTRACIÓN con los correos que este mecanismo ha puesto ahí. */
    public const MARK = 'sites.owners';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Pone al día todas las demostraciones.
     *
     * @return array<string,array{ok:bool,owners:int,error:string}> por identificador de sitio
     */
    public function syncAll(): array
    {
        $out = [];
        if (!Demos::isMaster($this->app)) {
            return $out;
        }
        $root = dirname($this->app->site()->dataDir);
        SiteResolver::forget();
        foreach (SiteResolver::all($root) as $row) {
            $id = (string) $row['id'];
            if ($id === $this->app->site()->id) {
                continue;
            }
            $site = SiteResolver::load($root, $id, $row['path'], $row['host'] === '*' ? '' : $row['host']);
            if ($site === null || !$site->isDemo() || $site->get('master', false)) {
                continue;
            }
            $out[$id] = $this->syncLoaded($site);
        }

        return $out;
    }

    /**
     * Pone al día una demostración.
     *
     * @return array{ok:bool,owners:int,error:string}
     */
    public function syncSite(string $id): array
    {
        if (!Demos::isMaster($this->app) || !SiteResolver::validId($id) || $id === $this->app->site()->id) {
            return ['ok' => false, 'owners' => 0, 'error' => 'Not a demo of this installation'];
        }
        SiteResolver::forget();
        $site = SiteResolver::load(dirname($this->app->site()->dataDir), $id);
        if ($site === null || !$site->isDemo() || $site->get('master', false)) {
            return ['ok' => false, 'owners' => 0, 'error' => 'Not a demo of this installation'];
        }

        return $this->syncLoaded($site);
    }

    /**
     * Las personas administradoras activas del maestro.
     *
     * @return array<int,array<string,mixed>>
     */
    public function owners(): array
    {
        return $this->app->db()->select("SELECT email, name, password, locale, totp_secret FROM {users} WHERE role = 'admin' AND active = 1 ORDER BY id");
    }

    /**
     * @return array{ok:bool,owners:int,error:string}
     */
    private function syncLoaded(Site $site): array
    {
        // Las cuentas reales del operador nunca se copian a la demo pública de administración completa.
        if ($site->isDemo() && $site->get('public_admin_demo', false)) {
            return ['ok' => true, 'owners' => 0, 'error' => ''];
        }
        try {
            $db = $this->connect($site);
            if ($db === null || !$db->tableExists('users') || !$db->tableExists('roles')) {
                return ['ok' => false, 'owners' => 0, 'error' => 'The site has no panel tables yet'];
            }
            $done = $this->write($db, $site);
            // De paso, el papel «demo» al día (lo que puede ver y tocar la cuenta pública de la demostración).
            DemoGuard::refreshRole($db);
            $db = null;
            gc_collect_cycles();

            return ['ok' => true, 'owners' => $done, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'owners' => 0, 'error' => $e->getMessage()];
        }
    }

    private function write(Db $db, Site $site): int
    {
        $now = $this->app->clock()->sql();
        // El papel de administrador de la demostración, con todos los permisos (el de serie; si alguien lo borró, vuelve).
        $caps = $db->value('SELECT caps FROM {roles} WHERE code = ?', ['admin']);
        if (!is_string($caps)) {
            $db->insert('roles', ['code' => 'admin', 'name' => 'Administrator', 'caps' => '["*"]', 'system' => 1]);
        } elseif (!in_array('*', (array) json_decode($caps, true), true)) {
            $db->update('roles', ['caps' => '["*"]'], ['code' => 'admin']);
        }

        $from = new Crypto($this->app->site()->secret());
        $to = new Crypto($site->secret());
        $kept = [];
        foreach ($this->owners() as $owner) {
            $email = strtolower(trim((string) $owner['email']));
            if ($email === '') {
                continue;
            }
            $totp = null;
            if (isset($owner['totp_secret']) && is_string($owner['totp_secret']) && $owner['totp_secret'] !== '') {
                $plain = $from->decrypt($owner['totp_secret']);
                if ($plain === null || $plain === '') {
                    // Exige segundo paso y no se puede llevar: no se copia con solo contraseña.
                    continue;
                }
                $totp = $to->encrypt($plain);
            }
            $row = [
                'name' => (string) $owner['name'], 'password' => (string) $owner['password'], 'role' => 'admin',
                'locale' => (string) $owner['locale'], 'totp_secret' => $totp, 'active' => 1, 'updated_at' => $now,
            ];
            $id = $db->value('SELECT id FROM {users} WHERE email = ?', [$email]);
            if ($id === null || $id === false) {
                $db->insert('users', $row + ['email' => $email, 'created_at' => $now]);
            } else {
                $db->run(
                    'UPDATE {users} SET name = ?, password = ?, role = ?, locale = ?, totp_secret = ?, active = 1, updated_at = ? WHERE id = ?',
                    [$row['name'], $row['password'], 'admin', $row['locale'], $totp, $now, (int) $id]
                );
            }
            $kept[] = $email;
        }

        // Quien ya no es dueño: se desactiva la copia que este mecanismo puso (y solo esa).
        $before = $db->value('SELECT v FROM {settings} WHERE k = ?', [self::MARK]);
        $before = is_string($before) ? json_decode($before, true) : null;
        foreach (is_array($before) ? $before : [] as $email) {
            if (is_string($email) && !in_array($email, $kept, true)) {
                $db->run("UPDATE {users} SET active = 0, updated_at = ? WHERE email = ? AND role = 'admin'", [$now, $email]);
            }
        }
        $db->upsert('settings', ['k' => self::MARK, 'v' => (string) json_encode($kept)], ['k']);

        return count($kept);
    }

    /** La base de datos de otro sitio de la instalación (como hace el panel para contar sus pedidos de hoy). */
    private function connect(Site $site): ?Db
    {
        $config = $site->get('db', []);
        if (!is_array($config) || !isset($config['driver'])) {
            return null;
        }
        if ($config['driver'] === 'sqlite') {
            $file = $site->path('db.sqlite');
            if (!is_file($file)) {
                return null;
            }
            $config['path'] = $file;
        }

        return Db::connect($config);
    }
}
