<?php

namespace App\Queries;

use App\Models\PurchaseBill;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for GET /purchase-bills (F2; backlog P4.2-BE-03). Search covers the bill number, the supplier's bill
 * number and the supplier's name; overdue = posted, money still due, due date before today (06 §2). Newest first.
 */
final class PurchaseBillQuery
{
    public function page(array $f): LengthAwarePaginator
    {
        $q = PurchaseBill::query()->with('party')->select('purchase_bills.*');
        foreach (['status', 'payment_status', 'party_id', 'letter_of_credit_id'] as $col) {
            if (isset($f[$col])) {
                $q->where("purchase_bills.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('purchase_bills.bill_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('purchase_bills.bill_date', '<=', $f['date_to']);
        }
        if (isset($f['overdue'])) {
            $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $overdue = fn ($w) => $w->where('purchase_bills.status', 'posted')->where('purchase_bills.balance_due', '>', 0)->whereDate('purchase_bills.due_date', '<', $today);
            $f['overdue'] === 'true' ? $q->where($overdue) : $q->whereNot($overdue);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as sp', 'sp.id', '=', 'purchase_bills.party_id')
                ->where(fn ($w) => $w->where('purchase_bills.number', 'like', $term)->orWhere('purchase_bills.supplier_bill_no', 'like', $term)->orWhere('sp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-bill_date';

        return $q->orderBy('purchase_bills.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('purchase_bills.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }
}
