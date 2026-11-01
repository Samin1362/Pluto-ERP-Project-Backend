<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Global search (L1 results page, L2 command palette; 05 GET /search; FR-012; backlog P14.1-BE-03). `q` (2+ characters)
 * is matched against indexed columns of the active company only (BR-037):
 *  - document and voucher numbers and codes by prefix, or the number's digits at the end (`0012` finds INV-2026-0012);
 *  - an amount (`120000`, `1,20,000.00`, `৳ 500`) exactly against document and voucher totals;
 *  - names by the start of any word (`kar` finds "Karim Distributors" and "Abdul Karim");
 *  - a phone number by prefix.
 * Types the user cannot view are left out (FR-012); products never carry cost (BR-052). Each group has the total
 * number of matches and the first `limit` hits (exact matches first, then the newest). Letters of credit join when the
 * LC module ships.
 */
final class SearchQuery
{
    public const TYPES = ['party' => 'parties.view', 'sales_invoice' => 'sales.view', 'purchase_bill' => 'purchases.view', 'voucher' => 'accounting.view',
        'ledger' => 'accounting.view', 'product' => 'inventory.view'];

    private const STATUS = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'posted' => 'Posted',
        'reversed' => 'Reversed', 'cancelled' => 'Cancelled', 'unpaid' => 'Unpaid', 'part_paid' => 'Part paid', 'paid' => 'Paid'];

    private string $q;

    private string $prefix;

    private string $word;

    private ?string $amount;

    private ?string $digits;

    /** @param  callable(string): bool  $can */
    public function search(int $c, string $q, ?string $type, int $limit, callable $can): array
    {
        $this->q = trim($q);
        $like = addcslashes($this->q, '%_\\');
        $this->prefix = $like.'%';
        $this->word = '% '.$like.'%';
        $plain = str_replace([',', '৳', ' '], '', $this->q);
        $this->amount = preg_match('/^\d{1,13}(\.\d{1,2})?$/', $plain) ? (string) Money::of($plain) : null;
        $this->digits = preg_match('/^\d{2,}$/', $this->q) ? '%'.$this->q : null;

        $groups = [];
        foreach (self::TYPES as $t => $permission) {
            if (($type !== null && $type !== $t) || ! $can($permission)) {
                continue;
            }
            [$query, $map] = $this->{lcfirst(str_replace('_', '', ucwords($t, '_')))}($c);
            $count = (clone $query)->count();
            $groups[] = ['type' => $t, 'count' => $count, 'items' => $count === 0 ? [] : $query->limit($limit)->get()->map($map)->all()];
        }

        return ['query' => $this->q, 'groups' => $groups];
    }

    private function party(int $c): array
    {
        $q = DB::table('parties')->where('company_id', $c)->whereNull('deleted_at')
            ->where(fn (Builder $w) => $w->where('code', 'like', $this->prefix)->orWhere('name', 'like', $this->prefix)->orWhere('name', 'like', $this->word)
                ->when(preg_match('/^\+?\d{3,}$/', $this->q), fn ($w) => $w->orWhere('mobile', 'like', $this->prefix)->orWhere('alt_phone', 'like', $this->prefix)))
            ->orderByRaw('code = ? DESC, name LIKE ? DESC', [$this->q, $this->prefix])->orderBy('name')
            ->select(['id', 'code', 'name', 'party_type', 'mobile', 'district', 'status']);

        return [$q, fn ($r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => implode(' · ', array_filter([$r->code, $r->mobile, $r->district])),
            'badge' => $r->status === 'active' ? ($r->party_type === 'customer' ? 'Customer' : 'Supplier') : ucfirst((string) $r->status),
            'url' => ($r->party_type === 'customer' ? '/customers/' : '/suppliers/').$r->id]];
    }

    private function salesInvoice(int $c): array
    {
        return $this->document($c, 'sales_invoices', 'invoice_date', '/sales/invoices/', ['challan_no', 'customer_po_ref']);
    }

    private function purchaseBill(int $c): array
    {
        return $this->document($c, 'purchase_bills', 'bill_date', '/purchases/bills/', ['supplier_bill_no']);
    }

    /** @param  list<string>  $refs  other references matched by prefix */
    private function document(int $c, string $table, string $dateCol, string $url, array $refs): array
    {
        $q = DB::table("{$table} as d")->leftJoin('parties as p', 'p.id', '=', 'd.party_id')->where('d.company_id', $c)
            ->where(function (Builder $w) use ($refs) {
                $w->where('d.number', 'like', $this->prefix);
                foreach ($refs as $r) {
                    $w->orWhere("d.{$r}", 'like', $this->prefix);
                }
                if ($this->digits) {
                    $w->orWhere('d.number', 'like', $this->digits);
                }
                if ($this->amount) {
                    $w->orWhere('d.total', $this->amount);
                }
            })
            ->orderByRaw('d.number = ? DESC', [$this->q])->orderByDesc("d.{$dateCol}")->orderByDesc('d.id')
            ->select(['d.id', 'd.number', "d.{$dateCol} as date", 'd.total', 'd.status', 'd.payment_status', 'p.name as party']);

        return [$q, fn ($r) => ['id' => $r->id, 'title' => $r->number ?: 'Draft', 'subtitle' => implode(' · ', array_filter([$r->party, self::date($r->date), Taka::format(Money::of((string) $r->total))])),
            'badge' => self::STATUS[$r->status === 'posted' ? $r->payment_status : $r->status] ?? ucfirst((string) $r->status), 'url' => $url.$r->id]];
    }

    private function voucher(int $c): array
    {
        $types = ['journal' => 'Journal', 'payment' => 'Payment', 'receipt' => 'Receipt', 'contra' => 'Contra', 'expense' => 'Expense', 'income' => 'Income'];
        $q = DB::table('journal_entries')->where('company_id', $c)
            ->where(fn (Builder $w) => $w->where('voucher_number', 'like', $this->prefix)->orWhere('reference', 'like', $this->prefix)
                ->when($this->digits, fn ($w) => $w->orWhere('voucher_number', 'like', $this->digits))
                ->when($this->amount, fn ($w) => $w->orWhere('total_debit', $this->amount)))
            ->orderByRaw('voucher_number = ? DESC', [$this->q])->orderByDesc('entry_date')->orderByDesc('id')
            ->select(['id', 'voucher_number', 'entry_type', 'entry_date', 'total_debit', 'status', 'narration']);

        return [$q, fn ($r) => ['id' => $r->id, 'title' => $r->voucher_number, 'subtitle' => implode(' · ', array_filter([
            $types[$r->entry_type] ?? ucfirst(str_replace('_', ' ', (string) $r->entry_type)), self::date($r->entry_date), Taka::format(Money::of((string) $r->total_debit)),
            $r->narration ? mb_strimwidth((string) $r->narration, 0, 60, '…') : null])), 'badge' => self::STATUS[$r->status] ?? ucfirst((string) $r->status),
            'url' => "/accounting/vouchers/{$r->id}"]];
    }

    private function ledger(int $c): array
    {
        $q = DB::table('ledgers as l')->join('account_groups as g', 'g.id', '=', 'l.account_group_id')->where('l.company_id', $c)
            ->where(fn (Builder $w) => $w->where('l.code', 'like', $this->prefix)->orWhere('l.name', 'like', $this->prefix)->orWhere('l.name', 'like', $this->word))
            ->orderByRaw('l.code = ? DESC, l.name LIKE ? DESC', [$this->q, $this->prefix])->orderBy('l.code')
            ->select(['l.id', 'l.code', 'l.name', 'l.is_active', 'g.name as grp']);

        return [$q, fn ($r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => "{$r->code} · {$r->grp}", 'badge' => $r->is_active ? 'Ledger' : 'Inactive',
            'url' => "/accounting/ledgers/{$r->id}"]];
    }

    private function product(int $c): array
    {
        $q = DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')->where('p.company_id', $c)->whereNull('p.deleted_at')
            ->where(fn (Builder $w) => $w->where('p.code', 'like', $this->prefix)->orWhere('p.barcode', $this->q)->orWhere('p.name', 'like', $this->prefix)->orWhere('p.name', 'like', $this->word))
            ->orderByRaw('p.code = ? DESC, p.name LIKE ? DESC', [$this->q, $this->prefix])->orderBy('p.name')
            ->select(['p.id', 'p.code', 'p.name', 'p.item_type', 'p.is_active', 'p.selling_price', 'u.name as unit']);

        return [$q, fn ($r) => ['id' => $r->id, 'title' => $r->name, 'subtitle' => implode(' · ', array_filter([$r->code, $r->unit,
            $r->selling_price !== null ? 'Sells at '.Taka::format(Money::of((string) $r->selling_price)) : null])),
            'badge' => $r->is_active ? ($r->item_type === 'service' ? 'Service' : 'Product') : 'Inactive', 'url' => "/inventory/products/{$r->id}"]];
    }

    private static function date(?string $d): ?string
    {
        return $d ? CarbonImmutable::parse($d)->format('j M Y') : null;
    }
}
