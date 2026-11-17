<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Erp\ErpTasks;

return [
    'handlers' => [
        'erp.deliver' => [ErpTasks::class, 'deliver'],
    ],
    'cron' => [
        'erp.deliveries' => ['every' => 60, 'run' => [ErpTasks::class, 'deliveries'], 'description' => 'Sends the messages to the ERP that are due and retries the failed ones'],
        'erp.profiles' => ['every' => 60, 'run' => [ErpTasks::class, 'profiles'], 'description' => 'Runs the scheduled exports and imports whose time has come'],
        'erp.purge' => ['every' => 'daily@03:50', 'run' => [ErpTasks::class, 'purge'], 'description' => 'Removes expired export files, old idempotency keys and closed messages'],
    ],
];
