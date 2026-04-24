<?php
/**
 * Periodic cron handler — drains the outbound callback queue.
 *
 * FacturaScripts has a built-in cron runner that looks for plugins with a
 * Cron/Cron.php and executes run() at the scheduled interval defined in
 * FS admin (default 1 min).
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Cron;

use FacturaScripts\Core\Base\CronClass;
use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\CallbackQueue;

final class Cron extends CronClass
{
    public function run(): void
    {
        $this->job('wc-fs-sync-drain')
            ->everyMinutes(1)
            ->run(function (): void {
                CallbackQueue::drain(30);
            });
    }
}
