<?php
/**
 * wc-facturascripts-sync — FacturaScripts plugin bootstrap.
 *
 * @author  Wizarck
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync;

use FacturaScripts\Core\Base\InitClass;
use FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model\FacturaCliente as FacturaClienteExtension;
use FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model\AlbaranCliente as AlbaranClienteExtension;
use FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model\ReciboCliente as ReciboClienteExtension;

/**
 * Bootstrap. FacturaScripts calls init() on every request after the plugin
 * is enabled. Idempotent.
 *
 * Responsibilities:
 *   - Register Extension classes that pipe into core Models to observe
 *     saves and dispatch HTTP callbacks to the WordPress side.
 *   - Ensure the callback retry queue table exists.
 */
final class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new FacturaClienteExtension());
        $this->loadExtension(new AlbaranClienteExtension());
        $this->loadExtension(new ReciboClienteExtension());
    }

    public function update(): void
    {
        // dbDelta-equivalent: CallbackQueue creates its own table lazily.
        Worker\CallbackQueue::ensure_schema();
    }
}
