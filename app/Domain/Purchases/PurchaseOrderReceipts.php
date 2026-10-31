<?php

namespace App\Domain\Purchases;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Quantity;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;

/**
 * Keeps purchase orders in step with their bills (BR-056, TC-PUR-009; backlog P10.2-BE-01). Called inside the bill's
 * posting or cancelling transaction, after the bill row is locked: each bill line linked to an order line adds (posted)
 * or takes back (cancelled) its quantity in received_quantity, then the order's status follows what has arrived —
 * received when every line is in full, partly_received when something is, sent when nothing is any more. Closed and
 * cancelled orders keep their status.
 */
final class PurchaseOrderReceipts
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function billPosted(PurchaseBill $bill): void
    {
        $this->apply($bill, 1);
    }

    public function billCancelled(PurchaseBill $bill): void
    {
        $this->apply($bill, -1);
    }

    private function apply(PurchaseBill $bill, int $sign): void
    {
        if (! $bill->purchase_order_id) {
            return;
        }
        $po = PurchaseOrder::whereKey($bill->purchase_order_id)->lockForUpdate()->first();
        if (! $po) {
            return;
        }
        $lines = PurchaseOrderLine::where('purchase_order_id', $po->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($bill->lines as $l) {
            if (! $l->purchase_order_line_id || ! isset($lines[$l->purchase_order_line_id])) {
                continue;
            }
            $line = $lines[$l->purchase_order_line_id];
            $q = Quantity::of($line->received_quantity);
            $q = $sign > 0 ? $q->plus(Quantity::of($l->quantity)) : $q->minus(Quantity::of($l->quantity));
            $line->received_quantity = $q->isNegative() ? Quantity::zero() : $q;
            $line->save();
        }
        if (in_array($po->status, ['closed', 'cancelled'], true)) {
            return;
        }
        $full = $lines->every(fn ($l) => ! Quantity::of($l->quantity)->isGreaterThan(Quantity::of($l->received_quantity)));
        $some = $lines->contains(fn ($l) => Quantity::of($l->received_quantity)->isPositive());
        $status = $full ? 'received' : ($some ? 'partly_received' : 'sent');
        if ($status !== $po->status) {
            $old = $po->status;
            $po->status = $status;
            $po->save();
            $this->audit->record('updated', $po, ['status' => $old], ['status' => $status, 'bill' => $bill->number]);
        }
    }
}
