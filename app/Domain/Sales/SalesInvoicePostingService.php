<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\CompanySetting;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use App\Support\Problem\ErrorCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts a draft sales invoice (06 §4, §5; BR-001, BR-004, BR-009, BR-014, BR-016, BR-053, BR-064; backlog P5.1-BE-03).
 * In one transaction: lock the invoice (still a draft?), check the period and that customer, warehouse and products
 * are active, lock the stock rows in product/warehouse order and check every stocked line at once (insufficient_stock
 * unless negative stock is allowed), take each out at the warehouse's average cost and store that cost on the line,
 * then journal
 *   Dr customer (total) · Cr each income ledger (net, merged) · Cr the tax rule's ledger (tax) · Cr/Dr rounding ·
 *   Dr COGS · Cr warehouse inventory (stocked lines' cost)
 * under the invoice's own number, link it, and mark the invoice posted. Any failure rolls everything back.
 * Warnings (credit limit, overdue) never block (BR-015); acknowledge_warnings is accepted for the record only.
 * A repeat with the same Idempotency-Key replays the response (middleware); with a new key it is already_posted.
 */
final class SalesInvoicePostingService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    public function post(SalesInvoice $invoice, bool $confirmBackdated = false): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $confirmBackdated) {
            $invoice = SalesInvoice::with('lines')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'posted') {
                throw new ApiProblem('already_posted', "{$invoice->number} is already posted.");
            }
            if ($invoice->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$invoice->number} is {$invoice->status}; only a draft can be posted.");
            }
            $this->periods->assertOpen($invoice->invoice_date);
            $this->assertNotBackdated($invoice, $confirmBackdated);
            [$party, $warehouse, $products] = $this->activeReferences($invoice);
            $lines = $invoice->lines->values();
            $stocked = $lines->filter(fn ($l) => $products[$l->product_id]->track_stock);

            $costs = $this->stock->stockOutMany($stocked->mapWithKeys(fn ($l, $i) => [$i => ['product' => $products[$l->product_id], 'warehouse' => $warehouse,
                'qty' => Quantity::of($l->quantity), 'source_line_id' => $l->id]])->all(), $invoice, $invoice->invoice_date);

            $b = new JournalBuilder;
            $b->debit($party->ledger_id, $invoice->total, ['against_reference' => $invoice->number]);
            $rules = TaxRule::whereIn('id', $lines->pluck('tax_rule_id')->filter())->get()->keyBy('id');
            $cogs = Money::zero();
            foreach ($lines as $i => $line) {
                $b->credit($line->income_ledger_id, $line->net_amount);
                if ($line->tax_rule_id && $line->tax_amount->isPositive()) {
                    $b->credit($rules[$line->tax_rule_id]->ledger_id, $line->tax_amount, ['tax_rule_id' => $line->tax_rule_id]);
                }
                if (isset($costs[$i])) { // BR-016: the average cost at posting is kept on the line
                    $line->forceFill(['unit_cost' => (string) UnitCost::of($costs[$i]->dividedBy(Quantity::of($line->quantity), UnitCost::SCALE)),
                        'cost_amount' => (string) $costs[$i]])->save();
                    $cogs = $cogs->plus($costs[$i]);
                }
            }
            if ($invoice->rounding_adjustment->isPositive()) {
                $b->credit($this->mapper->ledgerFor('rounding'), $invoice->rounding_adjustment);
            } elseif ($invoice->rounding_adjustment->isNegative()) {
                $b->debit($this->mapper->ledgerFor('rounding'), $invoice->rounding_adjustment->abs());
            }
            if ($cogs->isPositive()) {
                $b->debit($this->cogsLedger(), $cogs)->credit($warehouse->inventory_ledger_id, $cogs, ['warehouse_id' => $warehouse->id]);
            }

            $entry = $this->posting->persist($b, $invoice, 'sales_invoice', $invoice->invoice_date, $invoice->number,
                "Invoice {$invoice->number} to {$party->name}", $invoice->customer_po_ref, $invoice->branch_id);
            $this->stock->linkJournal($invoice, $entry->id);
            $invoice->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'posted_by' => Auth::id(), 'balance_due' => $invoice->total,
                'party_snapshot' => ['code' => $party->code, 'name' => $party->name, 'mobile' => $party->mobile, 'address' => $party->address]])->save(); // frozen for reprints (R-30)
            $this->audit->record('posted', $invoice, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry->id, 'total' => (string) $invoice->total]);

            return $invoice->refresh()->load('lines');
        }, attempts: 2); // a MySQL deadlock (1213) is retried once (09 §9); the fixed lock order should make it rare
    }

    /**
     * POST /sales-invoices/bulk-post (E2 bulk action; ERR-5, TC-SAL-016): each draft posts in its own transaction, so
     * one that lacks stock fails alone and the others still post. Per-item outcome: {succeeded: [ids], failed: [{id,
     * code, detail}]}. Back-dated drafts are not confirmed in bulk; they fail with backdated_confirmation_required.
     *
     * @param  list<int>  $ids
     * @return array{succeeded: list<int>, failed: list<array{id: int, code: string, detail: string}>}
     */
    public function bulkPost(array $ids): array
    {
        $out = ['succeeded' => [], 'failed' => []];
        foreach ($ids as $id) {
            try {
                $this->post(SalesInvoice::find($id) ?? throw new ApiProblem('not_found', "Invoice {$id} was not found."));
                $out['succeeded'][] = $id;
            } catch (ApiProblem $e) {
                $out['failed'][] = ['id' => $id, 'code' => $e->errorCode, 'detail' => $e->detail ?? ErrorCatalogue::title($e->errorCode)];
            }
        }

        return $out;
    }

    /** BR-064: COGS must be an expense ledger; a mapping to any other nature is refused before it reaches the books (TC-ACC-024). */
    private function cogsLedger(): Ledger
    {
        $ledger = $this->mapper->ledgerFor('cogs');
        $nature = DB::table('account_groups')->where('id', $ledger->account_group_id)->value('nature');
        if ($nature !== 'expense') {
            throw new ApiProblem('mapping_missing', "\"cogs\" is mapped to {$ledger->code} {$ledger->name}, a {$nature} ledger; map it to an expense ledger in Accounting defaults.",
                extra: ['mapping_key' => 'cogs']);
        }

        return $ledger;
    }

    /** BR-053: an invoice dated before the back-date window needs explicit confirmation. */
    private function assertNotBackdated(SalesInvoice $invoice, bool $confirmed): void
    {
        $window = (int) (CompanySetting::value('backdate_warning_days') ?? 0);
        if ($window > 0 && ! $confirmed && $invoice->invoice_date->lt(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window))) {
            throw new ApiProblem('backdated_confirmation_required', "{$invoice->number} is dated {$invoice->invoice_date->format('j M Y')}, more than {$window} days ago; confirm to post it.",
                errors: ['confirm_backdated' => ['Confirm the back-dated invoice']]);
        }
    }

    /** @return array{0: Party, 1: Warehouse, 2: Collection<int, Product>} */
    private function activeReferences(SalesInvoice $invoice): array
    {
        $party = Party::findOrFail($invoice->party_id);
        $warehouse = Warehouse::findOrFail($invoice->warehouse_id);
        $products = Product::withTrashed()->whereIn('id', $invoice->lines->pluck('product_id'))->get()->keyBy('id');
        $errors = [];
        if ($party->status !== 'active') {
            $errors['party_id'] = ["{$party->name} is inactive"];
        }
        if ($warehouse->status === 'inactive') {
            $errors['warehouse_id'] = ["{$warehouse->name} is inactive"];
        }
        foreach ($invoice->lines->values() as $i => $l) {
            $p = $products[$l->product_id];
            if (! $p->is_active || $p->trashed()) {
                $errors["lines.{$i}.product_id"] = ["{$p->name} is inactive"];
            }
        }
        if ($errors) {
            throw new ApiProblem('inactive_reference', "{$invoice->number} uses inactive records; reactivate them or edit the draft.", errors: $errors);
        }

        return [$party, $warehouse, $products];
    }
}
