<?php

namespace App\Queries;

use App\Models\Quotation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for GET /quotations (E13; backlog P10.1-BE-01). Search covers the QT number and the customer's name;
 * newest first. Each row carries the invoice it was converted into.
 */
final class QuotationQuery
{
    public function page(array $f): LengthAwarePaginator
    {
        $q = Quotation::query()->with(['party', 'invoice:id,quotation_id,number'])->select('quotations.*');
        foreach (['status', 'party_id'] as $col) {
            if (isset($f[$col])) {
                $q->where("quotations.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('quotations.quotation_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('quotations.quotation_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as cp', 'cp.id', '=', 'quotations.party_id')
                ->where(fn ($w) => $w->where('quotations.number', 'like', $term)->orWhere('cp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-quotation_date';

        return $q->orderBy('quotations.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('quotations.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }
}
