<?php
/**
 * ReciboCliente save-hooks — payment reconciliation → WP.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model;

use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\CallbackQueue;
use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\WpBridge;

/**
 * ReciboCliente. When a recibo is marked "pagado" (either manually or via
 * ConciliacionBancaria norma43 import) we notify WP so the WC order can
 * auto-transition to 'completed' with paid=true.
 *
 * We fire exactly one event per recibo transition: bank.norma43.reconcile.hit.
 */
class ReciboCliente
{
    public function saveUpdate()
    {
        return function (array $values = []): bool {
            // Only fire when the recibo transitions into paid state.
            $pagado   = (bool) ($this->pagado ?? false);
            $previous = (bool) ($values['_previous_pagado'] ?? false);
            if (! $pagado || $pagado === $previous) {
                return true;
            }

            $correlation_id = WpBridge::generate_correlation_id();

            CallbackQueue::enqueue(
                'bank.norma43.reconcile.hit',
                $correlation_id,
                array(
                    'fs_resource'  => 'recibosclientes',
                    'fs_id'        => $this->primaryColumnValue(),
                    'idfactura'    => (int) ($this->idfactura ?? 0),
                    'importe'      => (float) ($this->importe ?? 0),
                    'fechapago'    => (string) ($this->fechapago ?? ''),
                    'codcliente'   => (string) ($this->codcliente ?? ''),
                    'reconciled_by'=> (string) ($this->codigo_banco ?? 'manual'),
                )
            );
            return true;
        };
    }
}
