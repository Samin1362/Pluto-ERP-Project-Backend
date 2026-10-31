<?php

namespace App\Domain\Purchases;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders (F8, F11, D12; backlog P10.2-BE-01; BR-056, BR-006, BR-025). Lines are priced by the bill preparer
 * (same supplier currency, tax rules and checks as a bill) and stored with the product snapshot; nothing posts. The PO
 * number is reserved at first save in the year of the order date and voided when a draft is deleted.
 *
 * States (06 §2): draft → sent → partly_received → received → closed, or cancelled. Draft and sent orders can be edited
 * and sent; goods arriving (PurchaseOrderReceipts, from bill posting) move it to partly_received / received; closing
 * stops waiting for the rest; cancelling is only possible before anything arrives. Converting creates a draft bill for
 * what is still outstanding (ordered − received − already on a draft bill).
 */
final class PurchaseOrderService
{
    private const EDITABLE = ['draft', 'sent'];

    private const AUDITED = ['party_id', 'order_date', 'expected_date', 'warehouse_id', 'payment_term_id', 'exchange_rate', 'subtotal', 'discount_total',
        'tax_total', 'total', 'notes'];

    public function __construct(
        private readonly PurchaseBillPreparer $preparer,
        private readonly PurchaseBillService $bills,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): PurchaseOrder
    {
        return DB::transaction(function () use ($in) {
            $prepared = $this->price($in);
            $po = new PurchaseOrder(['status' => 'draft']);
            $po->number = $this->numbers->next('purchase_order', $this->fiscalYearId($in['order_date']))['number'];
            $this->fill($po, $prepared, $in);
            $po->save();
            $this->saveLines($po, $prepared);
            $this->audit->record('created', $po, null, $this->audited($po) + ['number' => $po->number]);

            return $po->refresh()->load('lines');
        });
    }

    public function update(PurchaseOrder $po, array $in, int $expectedVersion): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $in, $expectedVersion) {
            $po = $this->lock($po);
            if (! in_array($po->status, self::EDITABLE, true)) {
                throw new ApiProblem('invalid_transition', "{$po->number} is {$this->label($po->status)}; only a draft or sent order can be changed.", extra: ['status' => $po->status]);
            }
            if ((int) $po->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $po->lock_version]);
            }
            if (PurchaseBill::where('purchase_order_id', $po->id)->where('status', 'draft')->exists()) {
                throw new ApiProblem('invalid_transition', "{$po->number} already has a draft bill; delete the bill before changing the order.", extra: ['status' => $po->status]);
            }
            $prepared = $this->price($in);
            $this->fiscalYearId($in['order_date']);
            $old = $this->audited($po);
            $this->fill($po, $prepared, $in);
            $po->lines()->delete(); // lines are replaced as a whole (PUT)
            $po->save();
            $this->saveLines($po, $prepared);
            $this->audit->record('updated', $po, $old, $this->audited($po->refresh()));

            return $po->load('lines');
        });
    }

    /** BR-025: drafts only (and never one a bill was made from). The number is voided, not freed (BR-006). */
    public function delete(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po) {
            $po = $this->lock($po);
            if ($po->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$po->number} is {$this->label($po->status)}; only drafts can be deleted.", extra: ['status' => $po->status]);
            }
            if (PurchaseBill::where('purchase_order_id', $po->id)->exists()) {
                throw new ApiProblem('not_draft', "A bill was made from {$po->number}; cancel the order instead.", extra: ['status' => $po->status]);
            }
            $old = $this->audited($po) + ['number' => $po->number];
            $po->lines()->delete();
            $po->delete();
            $this->audit->record('voided_number', $po, ['number' => $po->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $po, $old);
        });
    }

    /** POST /purchase-orders/{id}/send: draft → sent (sending again keeps it sent); freezes the supplier's details. */
    public function markSent(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po);
            if (! in_array($po->status, self::EDITABLE, true)) {
                throw new ApiProblem('invalid_transition', "{$po->number} is {$this->label($po->status)}; only a draft or sent order can be sent.", extra: ['status' => $po->status]);
            }
            $old = ['status' => $po->status];
            $po->status = 'sent';
            $po->party_snapshot = ['code' => $po->party->code, 'name' => $po->party->name, 'mobile' => $po->party->mobile, 'address' => $po->party->address];
            $po->save();
            $this->audit->record('updated', $po, $old, ['status' => 'sent']);

            return $po->load('lines');
        });
    }

    /** POST /purchase-orders/{id}/status: closed (stop expecting the rest) or cancelled (nothing received yet). */
    public function setStatus(PurchaseOrder $po, string $status, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $status, $reason) {
            $po = $this->lock($po);
            $from = $status === 'closed' ? ['sent', 'partly_received', 'received'] : ['draft', 'sent'];
            if (! in_array($po->status, $from, true)) {
                $why = $status === 'cancelled' && in_array($po->status, ['partly_received', 'received'], true)
                    ? 'goods have arrived against it; close it instead' : "it cannot be {$status}";
                throw new ApiProblem('invalid_transition', "{$po->number} is {$this->label($po->status)}; {$why}.", extra: ['status' => $po->status]);
            }
            if ($status === 'cancelled' && PurchaseBill::where('purchase_order_id', $po->id)->where('status', 'draft')->exists()) {
                throw new ApiProblem('invalid_transition', "{$po->number} has a draft bill; delete the bill before cancelling the order.", extra: ['status' => $po->status]);
            }
            $old = ['status' => $po->status];
            $po->status = $status;
            $po->save();
            $this->audit->record($status === 'cancelled' ? 'cancelled' : 'updated', $po, $old, array_filter(['status' => $status, 'reason' => $reason]));

            return $po->load('lines');
        });
    }

    /**
     * POST /purchase-orders/{id}/convert (BR-056, TC-PUR-009): a draft bill dated today with, per line, what is still
     * outstanding (ordered − received − on other draft bills), at the order's cost, currency and planning rate, linked
     * line by line. The supplier's bill number starts as the PO number (the user types the real one before posting).
     */
    public function convert(PurchaseOrder $po): PurchaseBill
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po)->load('lines');
            if (! in_array($po->status, ['draft', 'sent', 'partly_received'], true)) {
                throw new ApiProblem('invalid_transition', "{$po->number} is {$this->label($po->status)}; nothing more is expected on it.", extra: ['status' => $po->status]);
            }
            $pending = DB::table('purchase_bill_lines as l')->join('purchase_bills as b', 'b.id', '=', 'l.purchase_bill_id')
                ->where('b.purchase_order_id', $po->id)->where('b.status', 'draft')->whereNotNull('l.purchase_order_line_id')
                ->groupBy('l.purchase_order_line_id')->selectRaw('l.purchase_order_line_id, SUM(l.quantity) q')->pluck('q', 'purchase_order_line_id');
            $lines = [];
            foreach ($po->lines as $l) {
                $left = Quantity::of($l->quantity)->minus(Quantity::of($l->received_quantity))->minus(Quantity::of((string) ($pending[$l->id] ?? '0')));
                if ($left->isPositive()) {
                    $lines[] = ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => (string) $left, 'unit_cost' => (string) $l->unit_cost,
                        'discount_pct' => (string) $l->discount_pct, 'tax_rule_id' => $l->tax_rule_id, 'purchase_order_line_id' => $l->id];
                }
            }
            if ($lines === []) {
                $draft = PurchaseBill::where('purchase_order_id', $po->id)->where('status', 'draft')->value('number');
                throw new ApiProblem('invalid_transition', $draft ? "Everything left on {$po->number} is already on draft bill {$draft}." : "Everything on {$po->number} has been received.",
                    extra: ['status' => $po->status]);
            }
            $currency = $po->currencyCode();
            $bill = $this->bills->createDraft([
                'party_id' => $po->party_id, 'supplier_bill_no' => $this->placeholderBillNo($po), 'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
                'payment_term_id' => $po->payment_term_id, 'warehouse_id' => $po->warehouse_id, 'purchase_order_id' => $po->id, 'currency_code' => $currency,
                'exchange_rate' => $currency === 'BDT' ? null : (string) $po->exchange_rate, 'notes' => $po->notes, 'lines' => $lines,
            ]);
            $this->audit->record('updated', $po, null, ['bill_created' => $bill->number]);

            return $bill;
        });
    }

    /**
     * POST /purchase-orders/from-low-stock (D12): rows grouped by the product's preferred supplier and warehouse, one
     * draft PO per group. Quantity defaults to the reorder quantity, else what brings stock back to the reorder level;
     * cost to the last purchase cost, else the warehouse's average cost. A row without a preferred supplier, or a group
     * the order checks refuse (inactive supplier, foreign currency without a rate), is reported per product.
     *
     * @param  list<array{product_id: int, warehouse_id: int, quantity?: ?string}>  $rows
     * @return array{succeeded: list<int>, failed: list<array{id: int, code: string, detail: string}>}
     */
    public function fromLowStock(array $rows, string $orderDate): array
    {
        $products = Product::with('preferredSupplier')->whereIn('id', array_column($rows, 'product_id'))->get()->keyBy('id');
        $groups = [];
        $failed = [];
        foreach ($rows as $r) {
            $p = $products[$r['product_id']];
            if (! $p->preferred_supplier_id) {
                $failed[] = ['id' => $p->id, 'code' => 'validation_failed', 'detail' => "{$p->name} has no preferred supplier; order it from a purchase order."];

                continue;
            }
            $groups["{$p->preferred_supplier_id}:{$r['warehouse_id']}"][] = ['product' => $p, 'row' => $r];
        }
        $succeeded = [];
        foreach ($groups as $key => $items) {
            [$supplierId, $warehouseId] = array_map('intval', explode(':', $key));
            try {
                $po = $this->create(['party_id' => $supplierId, 'order_date' => $orderDate, 'warehouse_id' => $warehouseId, 'source_note' => 'From the low-stock report',
                    'lines' => array_map(fn ($i) => ['product_id' => $i['product']->id, 'quantity' => $i['row']['quantity'] ?? $this->suggested($i['product'], $warehouseId),
                        'unit_cost' => $this->lastCost($i['product'], $warehouseId)], $items)]);
                $succeeded[] = $po->id;
            } catch (ApiProblem $e) {
                $detail = collect($e->errors)->flatten()->first() ?? $e->getMessage();
                foreach ($items as $i) {
                    $failed[] = ['id' => $i['product']->id, 'code' => $e->errorCode, 'detail' => "{$i['product']->name}: {$detail}"];
                }
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    private function suggested(Product $p, int $warehouseId): string
    {
        $s = DB::table('product_warehouse_settings')->where('product_id', $p->id)->where('warehouse_id', $warehouseId)->first(['reorder_level', 'reorder_qty']);
        if ($s && Quantity::of((string) $s->reorder_qty)->isPositive()) {
            return (string) Quantity::of((string) $s->reorder_qty);
        }
        $onHand = Quantity::of(StockBalance::where('product_id', $p->id)->where('warehouse_id', $warehouseId)->value('qty_on_hand') ?? '0');
        $short = Quantity::of((string) ($s->reorder_level ?? '0'))->minus($onHand);

        return (string) ($short->isPositive() ? $short : Quantity::of('1'));
    }

    private function lastCost(Product $p, int $warehouseId): string
    {
        $cost = $p->last_purchase_cost ?? StockBalance::where('product_id', $p->id)->where('warehouse_id', $warehouseId)->value('avg_unit_cost') ?? '0';

        return (string) UnitCost::of((string) $cost);
    }

    /** The PO number, or PO number-2, -3 … when this supplier already has a bill under it. */
    private function placeholderBillNo(PurchaseOrder $po): string
    {
        $no = $po->number;
        for ($n = 2; PurchaseBill::where('party_id', $po->party_id)->where('supplier_bill_no', $no)->exists(); $n++) {
            $no = "{$po->number}-{$n}";
        }

        return $no;
    }

    /** Priced like a bill dated on the order date; a line's purchase_order_line_id has no meaning on an order. */
    private function price(array $in): PreparedBill
    {
        $lines = array_map(fn ($l) => array_diff_key($l, ['purchase_order_line_id' => true]), $in['lines']);

        return $this->preparer->prepare(['party_id' => $in['party_id'], 'bill_date' => $in['order_date'], 'warehouse_id' => $in['warehouse_id'],
            'payment_term_id' => $in['payment_term_id'] ?? null, 'currency_code' => $in['currency_code'] ?? null, 'exchange_rate' => $in['exchange_rate'] ?? null,
            'lines' => $lines]);
    }

    /** The PO series runs per financial year; an order date outside every year cannot be numbered. */
    private function fiscalYearId(string $date): int
    {
        $id = FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->value('id');
        if (! $id) {
            throw new ApiProblem('validation_failed', errors: ['order_date' => ['No financial year covers this date; create the year in Settings › Financial years first']]);
        }

        return $id;
    }

    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function fill(PurchaseOrder $po, PreparedBill $p, array $in): void
    {
        $c = $p->calculation;
        $po->fill([
            'branch_id' => $p->warehouse->branch_id,
            'party_id' => $p->party->id,
            'party_snapshot' => ['code' => $p->party->code, 'name' => $p->party->name, 'mobile' => $p->party->mobile, 'address' => $p->party->address],
            'currency_code' => $p->currency,
            'order_date' => $in['order_date'], 'expected_date' => $in['expected_date'] ?? null, 'warehouse_id' => $p->warehouse->id,
            'payment_term_id' => $p->paymentTermId, 'exchange_rate' => $p->exchangeRate,
            'source_note' => $in['source_note'] ?? null, 'notes' => $in['notes'] ?? null, 'internal_note' => $in['internal_note'] ?? null,
            'subtotal' => $c->subtotal, 'discount_total' => $c->discountTotal, 'tax_total' => $c->taxTotal, 'rounding_adjustment' => $c->baseRounding,
            'total' => $c->baseTotal, 'total_foreign' => $p->foreign() ? $c->total : null,
        ]);
    }

    private function saveLines(PurchaseOrder $po, PreparedBill $p): void
    {
        foreach ($p->lines as $i => $l) {
            $c = $p->calculation->lines[$i];
            $po->lines()->create([
                'line_no' => $i + 1, 'product_id' => $l['product']->id, 'product_code' => $l['product']->code, 'product_name' => $l['product']->name,
                'unit_name' => $l['product']->unit?->name ?? '', 'description' => $l['input']['description'] ?? null,
                'quantity' => $l['input']['quantity'], 'unit_cost' => $l['input']['unit_cost'], 'discount_pct' => $l['input']['discount_pct'] ?? '0',
                'discount_amount' => $c->discountTotal(), 'tax_rule_id' => $c->taxRuleId, 'tax_rate' => $l['tax_rate']->toBigDecimal()->toScale(4, RoundingMode::HalfUp)->toString(),
                'tax_amount' => $c->tax, 'net_amount' => $c->net, 'line_total' => $c->total, 'received_quantity' => '0',
            ]);
        }
    }

    private function audited(PurchaseOrder $po): array
    {
        $a = [];
        foreach (self::AUDITED as $k) {
            $v = $po->getAttribute($k);
            $a[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_object($v) ? (string) $v : $v);
        }

        return $a;
    }
}
