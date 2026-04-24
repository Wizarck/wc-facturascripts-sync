<?php
/**
 * FacturaCliente save-hooks → WP callback.
 *
 * @license LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\WcFacturascriptsSync\Extension\Model;

use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\CallbackQueue;
use FacturaScripts\Plugins\WcFacturascriptsSync\Worker\WpBridge;

/**
 * Hooks piped into FacturaScripts\Core\Model\FacturaCliente via Dinamic
 * extension mechanism. We observe INSERT and UPDATE to emit:
 *   - invoice.fs.created   on INSERT
 *   - verifactu.aeat.ack   on UPDATE when fechafacturavf / cdvf populated
 *   - verifactu.aeat.rejected when the VeriFactu plugin sets an error flag
 *
 * FS pipes: pipe('saveInsert'), pipe('saveUpdate') fire AFTER DB write.
 * We intentionally enqueue callbacks instead of dispatching inline so a
 * slow WP side never holds a database transaction open.
 */
class FacturaCliente
{
    public function saveInsert()
    {
        return function (array $values = []): bool {
            $numero2         = (string) ($this->numero2 ?? '');
            $correlation_id  = '' !== $numero2 ? $numero2 : WpBridge::generate_correlation_id();

            CallbackQueue::enqueue(
                'invoice.fs.created',
                $correlation_id,
                array(
                    'fs_resource'       => 'facturaclientes',
                    'fs_id'             => $this->primaryColumnValue(),
                    'numero'            => (string) ($this->numero ?? ''),
                    'codcliente'        => (string) ($this->codcliente ?? ''),
                    'total'             => (float) ($this->total ?? 0),
                    'neto'              => (float) ($this->neto ?? 0),
                    'totaliva'          => (float) ($this->totaliva ?? 0),
                    'fecha'             => (string) ($this->fecha ?? ''),
                    'wc_order_id'       => (int) ($this->wc_order_id ?? 0),
                )
            );
            return true;
        };
    }

    public function saveUpdate()
    {
        return function (array $values = []): bool {
            // VeriFactu ack/rejection: driven by fields set by the VeriFactu
            // plugin. Names vary by FS release; we look at multiple known
            // aliases so an upstream rename doesn't silently break us.
            $status_field   = $this->verifactu_status ?? $this->vf_status ?? null;
            $correlation_id = (string) ($this->numero2 ?? WpBridge::generate_correlation_id());

            if ('ok' === $status_field || 'ack' === $status_field) {
                CallbackQueue::enqueue(
                    'verifactu.aeat.ack',
                    $correlation_id,
                    array(
                        'fs_resource' => 'facturaclientes',
                        'fs_id'       => $this->primaryColumnValue(),
                        'numero'      => (string) ($this->numero ?? ''),
                        'csv'         => (string) ($this->csv ?? $this->vf_csv ?? ''),
                    )
                );
                return true;
            }

            if ('rejected' === $status_field || 'error' === $status_field) {
                CallbackQueue::enqueue(
                    'verifactu.aeat.rejected',
                    $correlation_id,
                    array(
                        'fs_resource' => 'facturaclientes',
                        'fs_id'       => $this->primaryColumnValue(),
                        'numero'      => (string) ($this->numero ?? ''),
                        'error'       => (string) ($this->verifactu_error ?? $this->vf_error ?? ''),
                    )
                );
                return true;
            }

            return true;
        };
    }
}
