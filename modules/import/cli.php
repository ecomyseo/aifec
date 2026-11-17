<?php
/**
 * AIFEC — órdenes de consola del módulo de migración.
 *
 *   php aifec import:probe --source=… --mode=… --url=…         comprueba la conexión y cuenta lo que hay
 *   php aifec import:run [--dry-run] [--only=a,b] [--since=AAAA-MM-DD] --yes …   migra (o simula) trabajando aquí mismo (--only y --types son lo mismo)
 *   php aifec import:status [id]                                cómo va
 *   php aifec import:pause [id] · import:resume [id] · import:cancel [id]
 *   php aifec import:verify [id]                                comprobación final: cifras del origen contra las de aquí
 *   php aifec import:report [id] [--out=fichero.csv]            las notas de la migración en CSV
 *
 * Las claves van en variables de entorno (AIFEC_IMPORT_KEY, AIFEC_IMPORT_SECRET, AIFEC_IMPORT_COOKIE_KEY,
 * AIFEC_IMPORT_DB_PASSWORD) o se piden con --ask; escribirlas como opción las deja en el historial del shell.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\ImportCli;

return [
    'import:probe' => ['help' => 'Tests the connection to a source shop and counts what is there: import:probe --source=… --mode=…', 'run' => [ImportCli::class, 'probe']],
    'import:run' => ['help' => 'Migrates a shop (or simulates it with --dry-run): import:run --source=woocommerce|prestashop --mode=api|public|db [--dry-run] [--yes] [--only=a,b] [--types=a,b] [--since=YYYY-MM-DD] [--shop=ID] [--source-host=domain] [--order-prefix=TEXT] [--team-admins=0|1] [--keep-urls=0|1] [--no-images] [--overwrite-local] [--label=TEXT] [--ask] [--db-host=H] [--db-port=N] [--db-name=NAME] [--db-user=USER] [--db-password=KEY] and the fields of the reader (--url, --key, --secret…)', 'run' => [ImportCli::class, 'run']],
    'import:status' => ['help' => 'Shows how a migration goes: import:status [id]', 'run' => [ImportCli::class, 'status']],
    'import:pause' => ['help' => 'Pauses the migration in progress: import:pause [id]', 'run' => [ImportCli::class, 'pause']],
    'import:resume' => ['help' => 'Resumes a paused migration and works on it: import:resume [id]', 'run' => [ImportCli::class, 'resume']],
    'import:cancel' => ['help' => 'Cancels a migration that has not finished: import:cancel [id]', 'run' => [ImportCli::class, 'cancel']],
    'import:verify' => ['help' => 'Shows the final check of a migration: import:verify [id]', 'run' => [ImportCli::class, 'verify']],
    'import:report' => ['help' => 'Writes the notes of a migration as CSV: import:report [id] [--out=file.csv]', 'run' => [ImportCli::class, 'report']],
];
