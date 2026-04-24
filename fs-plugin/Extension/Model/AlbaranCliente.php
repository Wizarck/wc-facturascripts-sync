<?php
/**
 * AlbaranCliente save-hooks.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model;

use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\CallbackQueue;
use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\WpBridge;

/**
 * AlbaranCliente. INSERT → albaran.fs.created back to WP so the WP side
 * can persist _wc_fs_sync_albaran_numero on the WC order.
 */
class AlbaranCliente
{
    public function saveInsert()
    {
        return function (array $values = []): bool {
            $correlation_id = (string) ($this->numero2 ?? WpBridge::generate_correlation_id());

            CallbackQueue::enqueue(
                'albaran.fs.created',
                $correlation_id,
                array(
                    'fs_resource' => 'albaranclientes',
                    'fs_id'       => $this->primaryColumnValue(),
                    'numero'      => (string) ($this->numero ?? ''),
                    'codcliente'  => (string) ($this->codcliente ?? ''),
                    'total'       => (float) ($this->total ?? 0),
                    'fecha'       => (string) ($this->fecha ?? ''),
                    'wc_order_id' => (int) ($this->wc_order_id ?? 0),
                )
            );
            return true;
        };
    }
}
