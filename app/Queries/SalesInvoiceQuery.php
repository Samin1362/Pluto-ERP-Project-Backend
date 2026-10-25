<?php

namespace App\Queries;

use App\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for GET /sales-invoices (E2; backlog P5.1-BE-02). Search covers the invoice number, the customer's PO
 * reference and the customer's name; overdue = posted, money still due, due date before today (06 §2). Newest first.
 */
final class SalesInvoiceQuery
{
    public function page(array $f): LengthAwarePaginator
    {
        $q = SalesInvoice::query()->with('party')->select('sales_invoices.*');
        foreach (['status', 'payment_status', 'party_id'] as $col) {
            if (isset($f[$col])) {
                $q->where("sales_invoices.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('sales_invoices.invoice_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('sales_invoices.invoice_date', '<=', $f['date_to']);
        }
        if (isset($f['overdue'])) {
            $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $overdue = fn ($w) => $w->where('sales_invoices.status', 'posted')->where('sales_invoices.balance_due', '>', 0)->whereDate('sales_invoices.due_date', '<', $today);
            $f['overdue'] === 'true' ? $q->where($overdue) : $q->whereNot($overdue);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as cp', 'cp.id', '=', 'sales_invoices.party_id')
                ->where(fn ($w) => $w->where('sales_invoices.number', 'like', $term)->orWhere('sales_invoices.customer_po_ref', 'like', $term)->orWhere('cp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-invoice_date';

        return $q->orderBy('sales_invoices.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('sales_invoices.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }
}
