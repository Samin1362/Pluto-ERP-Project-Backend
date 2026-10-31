<?php

namespace App\Queries;

use App\Models\PurchaseOrder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for GET /purchase-orders (F11, and F3's "From PO" picker; backlog P10.2-BE-01). status=open means still
 * expecting goods (sent or partly received). Search covers the PO number and the supplier's name; newest first.
 */
final class PurchaseOrderQuery
{
    public function page(array $f): LengthAwarePaginator
    {
        $q = PurchaseOrder::query()->with('party')->select('purchase_orders.*');
        if (isset($f['status'])) {
            $f['status'] === 'open' ? $q->whereIn('purchase_orders.status', ['sent', 'partly_received']) : $q->where('purchase_orders.status', $f['status']);
        }
        if (isset($f['party_id'])) {
            $q->where('purchase_orders.party_id', $f['party_id']);
        }
        if (isset($f['currency'])) {
            $q->where('purchase_orders.currency_code', $f['currency']);
        }
        if (isset($f['date_from'])) {
            $q->whereDate('purchase_orders.order_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('purchase_orders.order_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as sp', 'sp.id', '=', 'purchase_orders.party_id')
                ->where(fn ($w) => $w->where('purchase_orders.number', 'like', $term)->orWhere('sp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-order_date';

        return $q->orderBy('purchase_orders.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('purchase_orders.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }
}
