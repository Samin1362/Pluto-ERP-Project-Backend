<?php

namespace App\Domain\Lc;

use App\Domain\Documents\LineUnits;
use App\Domain\Purchases\PurchaseOrderService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Http\Resources\MoneyAccountResource;
use App\Models\LcAmendment;
use App\Models\LcDocument;
use App\Models\LcShipment;
use App\Models\LetterOfCredit;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Letters of credit (M1–M6; FR-090–FR-096; 06 §12.1, §12.8; BR-039–BR-041; backlog P16.1-BE-01..04): create and edit
 * (draft or awaiting bank; an opened LC changes only through amendments), delete a draft, the status machine,
 * documents and checklist templates, shipment lots and amendments. Money postings live in LcPostingService.
 *
 * Items (PEP-487): the goods of an LC are kept as the lines of a linked purchase order, never as a second list. Items
 * entered on the LC create that order in the same transaction (LC currency, planning rate, marked sent without an
 * e-mail; `items_entered` = 1), so an import bill fills from what is still to come and BR-056 with the LC's tolerance
 * applies. While the LC is draft or awaiting the bank the items are replaced with the LC; once opened they change
 * only through an accepted amendment (lc_amendment_lines, applied by PurchaseOrderService::amendLines). An LC made
 * before items existed gets them once through addItems.
 *
 * Status machine (06 §12.1): draft → awaiting_bank (submit: supplier, amount, issuing bank, expiry; BR-039) → opened
 * (bank reference and date) | back to draft (refused); opened → partly_shipped (a lot ships) → documents_presented →
 * payment_due → closed (LcPostingService::close); opened/partly_shipped → expired (pluto:lc:expire); closed → opened
 * (reopen, with a reason); draft/awaiting → cancelled.
 */
final class LcService
{
    public const TRANSITIONS = [
        'submit' => [['draft'], 'awaiting_bank'], 'mark_opened' => [['awaiting_bank'], 'opened'], 'mark_refused' => [['awaiting_bank'], 'draft'],
        'documents_presented' => [['opened', 'partly_shipped'], 'documents_presented'], 'payment_due' => [['documents_presented'], 'payment_due'],
        'cancel' => [['draft', 'awaiting_bank'], 'cancelled'], 'expire' => [['opened', 'partly_shipped'], 'expired'], 'reopen' => [['closed'], 'opened'],
    ];

    private const FIELDS = ['party_id', 'purchase_order_id', 'intended_import', 'goods_description', 'quantity', 'quantity_unit', 'import_authorization_ref', 'lc_type',
        'tenor', 'usance_days', 'incoterm', 'currency_code', 'amount', 'tolerance_pct', 'planning_rate', 'partial_shipments', 'transhipment', 'confirmation_required',
        'issuing_bank_account_id', 'advising_bank', 'margin_pct', 'margin_account_id', 'application_date', 'latest_shipment_date', 'expiry_date', 'presentation_days', 'record_shipments'];

    public function __construct(private readonly CurrentCompany $current, private readonly DocumentNumberService $numbers, private readonly AuditLogger $audit,
        private readonly PurchaseOrderService $orders) {}

    public function create(array $in): LetterOfCredit
    {
        return DB::transaction(function () use ($in) {
            $this->assertSupplier((int) $in['party_id']);
            if (! empty($in['lines']) && ! empty($in['purchase_order_id'])) {
                throw $this->itemsOrOrder();
            }
            $date = $in['application_date'] ?? CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $fy = DB::table('fiscal_years')->where('company_id', $this->current->id())->where('start_date', '<=', $date)->where('end_date', '>=', $date)->value('id')
                ?? throw new ApiProblem('validation_failed', errors: ['application_date' => ['No financial year covers this date']]);
            $lc = new LetterOfCredit;
            $lc->fill(array_intersect_key($in, array_flip(self::FIELDS)));
            $lc->forceFill(['lc_number' => $this->numbers->next('letter_of_credit', (int) $fy)['number'], 'status' => 'draft', 'opening_status' => 'not_applied',
                'branch_id' => $this->headOfficeId()]);
            $po = empty($in['lines']) ? null : $this->createItemsOrder($lc, $in['lines'], $in['warehouse_id'] ?? null);
            $lc->save();
            $this->replaceDocuments($lc, $in);
            if (! empty($in['shipment'])) {
                $this->saveShipment($lc, $in['shipment'] + ['lot_no' => 1]);
            }
            $this->audit->record('created', $lc, null, ['lc_number' => $lc->lc_number, 'amount' => (string) $lc->amount]
                + ($po ? ['items' => $po->lines->count(), 'purchase_order' => $po->number] : []), type: 'letters_of_credit');

            return $lc->refresh();
        });
    }

    public function update(int $id, array $in, int $version): LetterOfCredit
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $lc = $this->lock($id, $version);
            if (! in_array($lc->status, ['draft', 'awaiting_bank'], true)) {
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} is {$lc->status}; an opened LC changes only through an amendment (BR-041).");
            }
            if (isset($in['party_id'])) {
                $this->assertSupplier((int) $in['party_id']);
            }
            // PEP-487: the order created from the LC's own items stays linked unless another order is chosen.
            $own = $lc->items_entered && $lc->purchase_order_id ? (int) $lc->purchase_order_id : null;
            $chosen = empty($in['purchase_order_id']) ? null : (int) $in['purchase_order_id'];
            if ($own && ($chosen === null || $chosen === $own)) {
                unset($in['purchase_order_id']);
                $chosen = null;
            }
            $lines = $in['lines'] ?? [];
            if ($lines !== [] && ($chosen || (! $own && ! array_key_exists('purchase_order_id', $in) && $lc->purchase_order_id))) {
                throw $this->itemsOrOrder();
            }
            $old = $lc->only(array_keys(array_intersect_key($in, array_flip(self::FIELDS))));
            $lc->fill(array_intersect_key($in, array_flip(self::FIELDS)));
            if ($lc->status === 'awaiting_bank') {
                $this->assertSubmittable($lc);
            }
            $items = null;
            if ($own && ($chosen || (array_key_exists('lines', $in) && $lines === []))) {
                $this->dropItemsOrder($lc, $own);
                $lc->forceFill(['purchase_order_id' => $chosen, 'items_entered' => false]);
                $items = ['items' => 0];
            } elseif ($own) {
                $po = $this->updateItemsOrder($lc, $own, array_key_exists('lines', $in) ? $lines : null, $in['warehouse_id'] ?? null);
                $items = $po ? ['items' => $po->lines->count(), 'purchase_order' => $po->number] : null;
            } elseif ($lines !== []) {
                $po = $this->createItemsOrder($lc, $lines, $in['warehouse_id'] ?? null);
                $items = ['items' => $po->lines->count(), 'purchase_order' => $po->number];
            }
            if ($items !== null && ! $lc->isDirty()) {
                $lc->lock_version = (int) $lc->lock_version + 1; // only the items changed: the LC's version still moves on (BR-049)
            }
            $lc->save();
            if ($lc->status === 'draft' && (isset($in['document_template_id']) || isset($in['documents']))) {
                $this->replaceDocuments($lc, $in);
            }
            $this->audit->record('updated', $lc, $old, $lc->only(array_keys($old)) + ($items ?? []), type: 'letters_of_credit');

            return $lc->refresh();
        });
    }

    // ---------------------------------------------------------------- items (PEP-487): kept on the linked purchase order

    /**
     * POST /letters-of-credit/{id}/items: catch-up for an LC made before items were kept on it. Only while it has no
     * purchase order and no bill; afterwards a draft is edited and an opened LC is amended (BR-041).
     */
    public function addItems(int $id, array $in): LetterOfCredit
    {
        return DB::transaction(function () use ($id, $in) {
            $lc = $this->lock($id);
            $this->assertNotFinal($lc);
            if ($lc->purchase_order_id) {
                $how = in_array($lc->status, ['draft', 'awaiting_bank'], true) ? 'edit the LC to change them' : 'they change only through an amendment (BR-041)';
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} already has its items; {$how}.");
            }
            $bill = PurchaseBill::where('letter_of_credit_id', $lc->id)->where('status', '!=', 'cancelled')->value('number');
            if ($bill) {
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} already has bill {$bill}; its items can no longer be added.");
            }
            $po = $this->createItemsOrder($lc, $in['lines'], $in['warehouse_id'] ?? null);
            $lc->save();
            $this->audit->record('items_added', $lc, null, ['items' => $po->lines->count(), 'purchase_order' => $po->number], type: 'letters_of_credit');

            return $lc->refresh();
        });
    }

    /** The order that keeps the LC's items: the supplier's, in the LC currency at the planning rate, sent (no e-mail). Sets the link on $lc without saving it. */
    private function createItemsOrder(LetterOfCredit $lc, array $lines, ?int $warehouseId): PurchaseOrder
    {
        $date = $lc->application_date?->toDateString() ?? CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $po = $this->orders->markSent($this->orders->create($this->orderInput($lc, $lines, $warehouseId ?? $this->defaultWarehouseId(), $date)));
        $lc->forceFill(['purchase_order_id' => $po->id, 'items_entered' => true]);

        return $po;
    }

    /**
     * Replaces the items (`$lines` null = keep them) and keeps the order in step with the LC's supplier, currency,
     * planning rate and arrival warehouse. Null when nothing had to change.
     */
    private function updateItemsOrder(LetterOfCredit $lc, int $orderId, ?array $lines, ?int $warehouseId): ?PurchaseOrder
    {
        $po = PurchaseOrder::with('lines')->findOrFail($orderId);
        $foreign = $lc->currency_code !== 'BDT';
        $same = $lines === null && (int) $po->party_id === (int) $lc->party_id && $po->currencyCode() === $lc->currency_code
            && ($warehouseId === null || $warehouseId === (int) $po->warehouse_id) && (! $foreign || $po->exchange_rate->isEqualTo($lc->planning_rate));
        if ($same) {
            return null;
        }
        $this->assertNoBill($lc, $po);
        $lines ??= $po->lines->map(fn (PurchaseOrderLine $l) => ['product_id' => $l->product_id, 'unit_id' => $l->unit_id, 'description' => $l->description,
            'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost, 'discount_pct' => (string) $l->discount_pct, 'tax_rule_id' => $l->tax_rule_id])->all();

        return $this->orders->update($po, $this->orderInput($lc, $lines, $warehouseId ?? (int) $po->warehouse_id, $po->order_date->toDateString(), $po), (int) $po->lock_version);
    }

    /** The items are taken off the LC: its own order is cancelled (the number is never reused, BR-006). */
    private function dropItemsOrder(LetterOfCredit $lc, int $orderId): void
    {
        $po = PurchaseOrder::findOrFail($orderId);
        $this->assertNoBill($lc, $po);
        $this->orders->setStatus($po, 'cancelled', "Items removed from {$lc->lc_number}");
    }

    /** Once a bill uses the order the items no longer change by editing (a draft bill is deleted first; goods received stay). */
    private function assertNoBill(LetterOfCredit $lc, PurchaseOrder $po): void
    {
        $bill = PurchaseBill::where('purchase_order_id', $po->id)->orderBy('id')->first(['number', 'status']);
        if ($bill) {
            throw new ApiProblem('invalid_transition', "{$lc->lc_number}: bill {$bill->number} ({$bill->status}) already uses its items on {$po->number}; the items cannot be changed here.");
        }
    }

    private function orderInput(LetterOfCredit $lc, array $lines, int $warehouseId, string $orderDate, ?PurchaseOrder $po = null): array
    {
        return ['party_id' => (int) $lc->party_id, 'order_date' => $orderDate, 'expected_date' => $po?->expected_date?->toDateString(), 'warehouse_id' => $warehouseId,
            'payment_term_id' => $po?->payment_term_id, 'currency_code' => $lc->currency_code, 'exchange_rate' => $lc->currency_code === 'BDT' ? null : (string) $lc->planning_rate,
            'source_note' => "Items of {$lc->lc_number}", 'notes' => $po?->notes, 'internal_note' => $po?->internal_note, 'lines' => array_values($lines)];
    }

    private function itemsOrOrder(): ApiProblem
    {
        return new ApiProblem('validation_failed', errors: ['lines' => ['Choose an existing purchase order or enter the items, not both']]);
    }

    private function headOfficeId(): ?int
    {
        return DB::table('branches')->where('company_id', $this->current->id())->orderByDesc('is_head_office')->orderBy('id')->value('id');
    }

    /** Where the goods arrive when the user did not say: the head office's first active warehouse, else the company's first active one. */
    private function defaultWarehouseId(): int
    {
        $id = Warehouse::where('status', 'active')->where('branch_id', $this->headOfficeId())->orderBy('id')->value('id') ?? Warehouse::where('status', 'active')->orderBy('id')->value('id');

        return $id ?? throw new ApiProblem('validation_failed', errors: ['warehouse_id' => ['Choose the warehouse the goods will arrive at']]);
    }

    /** LcLine[] (05 schema): the linked order's lines, in the LC currency, with what has arrived and what is still to come. */
    public static function lines(?PurchaseOrder $po): array
    {
        return $po === null ? [] : $po->lines()->get()->map(function (PurchaseOrderLine $l) {
            $left = Quantity::of($l->quantity)->minus(Quantity::of($l->received_quantity));

            return ['id' => $l->id, 'line_no' => (int) $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
                'unit' => (string) $l->unit_name, 'unit_id' => $l->unit_id, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost,
                'line_total' => (string) $l->line_total, 'received_quantity' => (string) $l->received_quantity,
                'remaining_quantity' => (string) ($left->isNegative() ? Quantity::zero() : $left)];
        })->all();
    }

    /**
     * Checks an amendment's item changes against the LC's items as they are now and returns the lc_amendment_lines
     * rows (with the values before). They are checked again, under the order lock, when the amendment is accepted.
     *
     * @return list<array<string, mixed>>
     */
    private function amendmentLineRows(LetterOfCredit $lc, array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $po = $lc->purchase_order_id ? PurchaseOrder::find($lc->purchase_order_id) : null;
        if (! $po) {
            throw new ApiProblem('validation_failed', errors: ['lines' => ["{$lc->lc_number} has no items yet; add its items first"]]);
        }
        $orderLines = PurchaseOrderLine::where('purchase_order_id', $po->id)->get()->keyBy('id');
        $taken = $this->orders->taken($po);
        $adds = array_filter($lines, fn ($l) => $l['action'] === 'add');
        $products = Product::with(['unit', 'units.unit'])->whereIn('id', array_filter(array_column($adds, 'product_id')))->get()->keyBy('id');
        $errors = [];
        $rows = [];
        $seen = [];
        $kept = $orderLines->count();
        foreach ($lines as $i => $l) {
            $row = ['action' => $l['action'], 'purchase_order_line_id' => null, 'description' => $l['description'] ?? null, 'old_quantity' => null, 'quantity' => null,
                'old_unit_cost' => null, 'unit_cost' => null];
            if ($l['action'] === 'add') {
                $p = $products[$l['product_id'] ?? 0] ?? null;
                foreach (['product_id' => 'Choose an item', 'quantity' => 'Enter the quantity', 'unit_cost' => 'Enter the unit cost'] as $f => $message) {
                    if (! isset($l[$f])) {
                        $errors["lines.{$i}.{$f}"] = [$message];
                    }
                }
                if ($p && ! $p->is_active) {
                    $errors["lines.{$i}.product_id"] = ["{$p->name} is inactive"];
                }
                if (! $p || isset($errors["lines.{$i}.quantity"]) || isset($errors["lines.{$i}.unit_cost"])) {
                    continue;
                }
                $unitErrors = [];
                $unit = LineUnits::resolve($products, [$l], $unitErrors)[0]; // BR-069
                if ($unitErrors) {
                    $errors["lines.{$i}.unit_id"] = $unitErrors['lines.0.unit_id'];
                }
                $rows[] = ['product_id' => $p->id, 'unit_id' => $unit['unit_id'], 'quantity' => (string) Quantity::of($l['quantity']), 'unit_cost' => (string) UnitCost::of($l['unit_cost'])] + $row;
                $kept++;

                continue;
            }
            $ol = $orderLines[$l['purchase_order_line_id'] ?? 0] ?? null;
            if (! $ol) {
                $errors["lines.{$i}.purchase_order_line_id"] = ["This is not an item of {$lc->lc_number}"];

                continue;
            }
            if (isset($seen[$ol->id])) {
                $errors["lines.{$i}.purchase_order_line_id"] = ["{$ol->product_name} is changed twice in this amendment"];

                continue;
            }
            $seen[$ol->id] = true;
            $has = $taken[$ol->id] ?? Quantity::zero();
            $unit = $ol->unit_name ? " {$ol->unit_name}" : '';
            $row = ['purchase_order_line_id' => $ol->id, 'product_id' => $ol->product_id, 'unit_id' => $ol->unit_id, 'old_quantity' => (string) $ol->quantity,
                'old_unit_cost' => (string) $ol->unit_cost] + $row;
            if ($l['action'] === 'remove') {
                if ($has->isPositive()) {
                    $errors["lines.{$i}.purchase_order_line_id"] = ["{$ol->product_name} cannot be removed: {$has}{$unit} is already received or on a bill"];
                }
                $kept--;
            } else {
                if (! isset($l['quantity']) && ! isset($l['unit_cost'])) {
                    $errors["lines.{$i}.quantity"] = ['Enter the new quantity or unit cost'];
                } elseif (isset($l['quantity']) && Quantity::of($l['quantity'])->isLessThan($has)) {
                    $errors["lines.{$i}.quantity"] = ["{$ol->product_name} cannot go below {$has}{$unit}: that much is already received or on a bill"];
                }
                $row = ['quantity' => isset($l['quantity']) ? (string) Quantity::of($l['quantity']) : null, 'unit_cost' => isset($l['unit_cost']) ? (string) UnitCost::of($l['unit_cost']) : null] + $row;
            }
            $rows[] = $row;
        }
        if (! $errors && $kept < 1) {
            $errors['lines'] = ["{$lc->lc_number} must keep at least one item"];
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }

        return $rows;
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $lc = LetterOfCredit::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($lc->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$lc->lc_number} is {$lc->status}; only a draft LC can be deleted (cancel it instead).");
            }
            if (DB::table('lc_charges')->where('letter_of_credit_id', $id)->exists() || DB::table('lc_payments')->where('letter_of_credit_id', $id)->exists()) {
                throw new ApiProblem('has_history', "{$lc->lc_number} has charges or payments; cancel it instead.");
            }
            DB::table('lc_documents')->where('letter_of_credit_id', $id)->delete();
            DB::table('lc_shipments')->where('letter_of_credit_id', $id)->delete();
            if ($lc->items_entered && $lc->purchase_order_id) {
                $this->dropItemsOrder($lc, (int) $lc->purchase_order_id); // the order made from its items goes with it (cancelled; refused once a bill uses it)
            }
            $this->audit->record('deleted', $lc, ['lc_number' => $lc->lc_number], null, type: 'letters_of_credit');
            $lc->delete();
        });
    }

    public function transition(int $id, array $in, int $version): LetterOfCredit
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $lc = $this->lock($id, $version);
            [$from, $to] = self::TRANSITIONS[$in['action']];
            if (! in_array($lc->status, $from, true)) {
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} is ".str_replace('_', ' ', $lc->status).'; it cannot be '.str_replace('_', ' ', $in['action']).'.');
            }
            $old = ['status' => $lc->status];
            $patch = ['status' => $to];
            switch ($in['action']) {
                case 'submit':
                    $this->assertSubmittable($lc);
                    app(LcFinanceService::class)->checkLcLimit($lc); // BR-066: block -> 422; warn -> the LC_LIMIT_EXCEEDED flag
                    $patch['opening_status'] = 'submitted';
                    break;
                case 'mark_opened':
                    $errors = array_filter(['bank_reference' => empty($in['bank_reference']) ? ['Enter the bank\'s LC reference'] : null, 'date' => empty($in['date']) ? ['Enter the date the bank opened it'] : null]);
                    if ($errors) {
                        throw new ApiProblem('validation_failed', errors: $errors);
                    }
                    $patch += ['opening_status' => 'opened', 'bank_reference' => $in['bank_reference'], 'opened_on' => $in['date']];
                    break;
                case 'mark_refused':
                    $patch += ['opening_status' => 'refused', 'bank_action_note' => $in['reason'] ?? null];
                    break;
                case 'payment_due':
                    if ($lc->tenor === 'usance' && $lc->usance_days && ! empty($in['date'])) {
                        $patch['maturity_date'] = CarbonImmutable::parse($in['date'])->addDays((int) $lc->usance_days)->toDateString();
                    }
                    break;
                case 'reopen':
                    if (empty($in['reason'])) {
                        throw new ApiProblem('validation_failed', errors: ['reason' => ['Say why the LC is reopened']]);
                    }
                    $patch += ['closed_on' => null, 'closed_by' => null, 'bank_action_note' => $in['reason']];
                    break;
                case 'cancel':
                case 'expire':
                    $patch['bank_action_note'] = $in['reason'] ?? $lc->bank_action_note;
                    break;
            }
            $lc->forceFill($patch)->save();
            if (in_array($in['action'], ['cancel', 'expire'], true)) {
                app(LcPostingService::class)->releaseMargin($lc, $in['action'] === 'cancel' ? 'cancelled' : 'expired');
            }
            // A cancelled LC no longer expects its items: the order made from them is cancelled too, unless a bill already uses it.
            if ($in['action'] === 'cancel' && $lc->items_entered && $lc->purchase_order_id && ! PurchaseBill::where('purchase_order_id', $lc->purchase_order_id)->exists()) {
                $po = PurchaseOrder::find($lc->purchase_order_id);
                if ($po && in_array($po->status, ['draft', 'sent'], true)) {
                    $this->orders->setStatus($po, 'cancelled', "{$lc->lc_number} was cancelled");
                }
            }
            if ($in['action'] === 'expire') {
                DB::table('lc_shipments')->where('letter_of_credit_id', $lc->id)->where('status', 'not_shipped')->update(['status' => 'not_shipped_expired', 'updated_at' => now()]);
            }
            $this->audit->record($in['action'], $lc, $old, ['status' => $to] + array_intersect_key($in, array_flip(['bank_reference', 'date', 'reason'])), type: 'letters_of_credit');

            return $lc->refresh();
        });
    }

    /** BR-039: what an LC needs before it goes to the bank. */
    private function assertSubmittable(LetterOfCredit $lc): void
    {
        $errors = array_filter([
            'party_id' => $lc->party_id ? null : ['Choose the supplier'],
            'amount' => $lc->amount && Money::of($lc->amount)->isPositive() ? null : ['Enter the LC amount'],
            'issuing_bank_account_id' => $lc->issuing_bank_account_id ? null : ['Choose the issuing bank'],
            'expiry_date' => $lc->expiry_date ? null : ['Enter the expiry date'],
        ]);
        if ($errors) {
            throw new ApiProblem('lc_submit_incomplete', 'Fill in the supplier, amount, issuing bank and expiry date before sending it to the bank.', errors: $errors);
        }
    }

    private function assertSupplier(int $partyId): void
    {
        if (Party::whereKey($partyId)->value('party_type') !== 'supplier') {
            throw new ApiProblem('validation_failed', errors: ['party_id' => ['Choose a supplier (the beneficiary)']]);
        }
    }

    public function lock(int $id, ?int $version = null): LetterOfCredit
    {
        $lc = LetterOfCredit::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($version !== null && (int) $lc->lock_version !== $version) {
            throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $lc->lock_version]);
        }

        return $lc;
    }

    // ---------------------------------------------------------------- documents and templates (M4, M6)

    /** Copies a template's items (R-40: the template id is used, not stored) or the explicit list into the LC's documents. */
    private function replaceDocuments(LetterOfCredit $lc, array $in): void
    {
        $items = $in['documents'] ?? null;
        if ($items === null && ! empty($in['document_template_id'])) {
            $items = DB::table('lc_document_template_items')->where('lc_document_template_id', $in['document_template_id'])->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($i) => ['name' => $i->name, 'provided_by' => $i->provided_by, 'copies' => $i->copies, 'note' => $i->note, 'is_required' => (bool) $i->required_by_default])->all();
        }
        if ($items === null) {
            return;
        }
        LcDocument::where('letter_of_credit_id', $lc->id)->delete();
        foreach ($items as $d) {
            $this->saveDocument($lc, $d);
        }
    }

    public function saveDocument(LetterOfCredit $lc, array $in, ?LcDocument $doc = null): LcDocument
    {
        $doc ??= new LcDocument(['letter_of_credit_id' => $lc->id]);
        $row = array_intersect_key($in, array_flip(['name', 'provided_by', 'copies', 'note', 'is_required', 'status', 'due_date', 'received_date', 'discrepancy_note', 'lc_shipment_id']));
        if (($row['status'] ?? null) === 'received' && empty($row['received_date']) && ! $doc->received_date) {
            $row['received_date'] = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        }
        if (($row['status'] ?? null) === 'discrepant' && empty($row['discrepancy_note']) && ! $doc->discrepancy_note) {
            throw new ApiProblem('validation_failed', errors: ['discrepancy_note' => ['Say what is wrong with the document']]);
        }
        $doc->fill($row);
        $doc->letter_of_credit_id = $lc->id;
        $doc->save();

        return $doc->refresh();
    }

    public function updateDocument(int $id, array $in, int $version): LcDocument
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $doc = LcDocument::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $doc->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $doc->lock_version]);
            }
            $lc = LetterOfCredit::findOrFail($doc->letter_of_credit_id);
            $this->assertNotFinal($lc);
            $old = $doc->only(['status', 'received_date', 'discrepancy_note']);
            $doc = $this->saveDocument($lc, $in, $doc);
            $this->audit->record('updated', $doc, $old, $doc->only(['status', 'received_date', 'discrepancy_note']), type: 'lc_documents');

            return $doc;
        });
    }

    public function addDocument(int $lcId, array $in): LcDocument
    {
        return DB::transaction(function () use ($lcId, $in) {
            $lc = LetterOfCredit::whereKey($lcId)->lockForUpdate()->firstOrFail();
            $this->assertNotFinal($lc);
            $doc = $this->saveDocument($lc, $in);
            $this->audit->record('created', $doc, null, $doc->only(['name', 'provided_by', 'copies', 'status']), type: 'lc_documents');

            return $doc;
        });
    }

    public static function document(LcDocument $d): array
    {
        $att = DB::table('attachments as a')->leftJoin('users as u', 'u.id', '=', 'a.created_by')->where('a.attachable_type', 'lc_document')->where('a.attachable_id', $d->id)
            ->orderBy('a.id')->get(['a.id', 'a.original_name', 'a.mime_type', 'a.size_bytes', 'a.created_at', 'u.id as uid', 'u.name as uname']);

        return ['id' => $d->id, 'name' => $d->name, 'provided_by' => $d->provided_by, 'copies' => (int) $d->copies, 'note' => $d->note, 'is_required' => (bool) $d->is_required,
            'status' => $d->status, 'due_date' => $d->due_date?->toDateString(), 'received_date' => $d->received_date?->toDateString(),
            'attachments' => $att->map(fn ($a) => ['id' => $a->id, 'original_name' => $a->original_name, 'mime_type' => $a->mime_type, 'size_bytes' => (int) $a->size_bytes,
                'uploaded_by' => ['id' => $a->uid, 'name' => $a->uname], 'created_at' => CarbonImmutable::parse($a->created_at)->toIso8601String()])->all(),
            'version' => (int) $d->lock_version];
    }

    public function templates(): array
    {
        return DB::table('lc_document_templates')->where('company_id', $this->current->id())->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($t) => self::template($t))->all();
    }

    public static function template(object $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'is_default' => (bool) $t->is_default, 'items' => DB::table('lc_document_template_items')->where('lc_document_template_id', $t->id)
            ->orderBy('sort_order')->orderBy('id')->get(['name', 'provided_by', 'copies', 'note', 'required_by_default'])
            ->map(fn ($i) => ['name' => $i->name, 'provided_by' => $i->provided_by, 'copies' => (int) $i->copies, 'note' => $i->note, 'required_by_default' => (bool) $i->required_by_default])->all()];
    }

    public function saveTemplate(array $in, ?int $id = null): array
    {
        return DB::transaction(function () use ($in, $id) {
            $c = $this->current->id();
            if ($id && ! DB::table('lc_document_templates')->where('company_id', $c)->where('id', $id)->exists()) {
                throw new ApiProblem('not_found');
            }
            if (DB::table('lc_document_templates')->where('company_id', $c)->where('name', $in['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw new ApiProblem('validation_failed', errors: ['name' => ['Another checklist has this name']]);
            }
            if ($in['is_default'] ?? false) {
                DB::table('lc_document_templates')->where('company_id', $c)->update(['is_default' => false]);
            }
            $row = ['name' => $in['name'], 'updated_by' => auth()->id(), 'updated_at' => now()] + (isset($in['is_default']) ? ['is_default' => (bool) $in['is_default']] : []);
            if ($id) {
                DB::table('lc_document_templates')->where('id', $id)->update($row);
            } else {
                $id = DB::table('lc_document_templates')->insertGetId($row + ['company_id' => $c, 'created_by' => auth()->id(), 'created_at' => now()]);
            }
            DB::table('lc_document_template_items')->where('lc_document_template_id', $id)->delete();
            foreach (array_values($in['items']) as $i => $item) {
                DB::table('lc_document_template_items')->insert(['lc_document_template_id' => $id, 'name' => $item['name'], 'provided_by' => $item['provided_by'],
                    'copies' => (int) ($item['copies'] ?? 1), 'note' => $item['note'] ?? null, 'required_by_default' => (bool) ($item['required_by_default'] ?? true), 'sort_order' => $i]);
            }
            $this->audit->record('template_saved', null, null, ['template_id' => $id, 'name' => $in['name']], type: 'lc_document_templates');

            return self::template(DB::table('lc_document_templates')->find($id));
        });
    }

    // ---------------------------------------------------------------- shipments (M4)

    public function saveShipment(LetterOfCredit $lc, array $in, ?LcShipment $s = null): LcShipment
    {
        $s ??= new LcShipment(['letter_of_credit_id' => $lc->id, 'lot_no' => $in['lot_no'] ?? ((int) LcShipment::where('letter_of_credit_id', $lc->id)->max('lot_no') + 1)]);
        if (! $s->exists && LcShipment::where('letter_of_credit_id', $lc->id)->where('lot_no', $s->lot_no)->exists()) {
            throw new ApiProblem('validation_failed', errors: ['lot_no' => ["Lot {$s->lot_no} already exists"]]);
        }
        if (! $s->exists && ! $lc->partial_shipments && LcShipment::where('letter_of_credit_id', $lc->id)->exists()) {
            throw new ApiProblem('validation_failed', errors: ['lot_no' => ['This LC does not allow partial shipments; amend it first']]);
        }
        $s->fill(array_intersect_key($in, array_flip(['status', 'booking_ref', 'bl_number', 'vessel', 'port_of_loading', 'port_of_discharge', 'mode', 'etd', 'eta',
            'shipped_on', 'arrived_on', 'quantity', 'value_foreign'])));
        $s->letter_of_credit_id = $lc->id;
        $s->save();
        // A lot on its way moves an opened LC to partly shipped (06 §12.1).
        if ($lc->status === 'opened' && in_array($s->status, ['at_sea', 'arrived', 'arrived_cleared'], true)) {
            $lc->forceFill(['status' => 'partly_shipped'])->save();
            $this->audit->record('partly_shipped', $lc, ['status' => 'opened'], ['status' => 'partly_shipped', 'lot_no' => $s->lot_no], type: 'letters_of_credit');
        }

        return $s->refresh();
    }

    public function addShipment(int $lcId, array $in): LcShipment
    {
        return DB::transaction(function () use ($lcId, $in) {
            $lc = LetterOfCredit::whereKey($lcId)->lockForUpdate()->firstOrFail();
            $this->assertNotFinal($lc);
            $lot = $this->saveShipment($lc, $in);
            $this->audit->record('created', $lot, null, $lot->only(['lot_no', 'status', 'bl_number']), type: 'lc_shipments');

            return $lot;
        });
    }

    public function updateShipment(int $id, array $in, int $version): LcShipment
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $s = LcShipment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $s->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $s->lock_version]);
            }
            $lc = LetterOfCredit::whereKey($s->letter_of_credit_id)->lockForUpdate()->firstOrFail();
            $this->assertNotFinal($lc);
            $old = $s->only(['status', 'bl_number', 'arrived_on']);
            unset($in['lot_no']);
            $s = $this->saveShipment($lc, $in, $s);
            $this->audit->record('updated', $s, $old, $s->only(['status', 'bl_number', 'arrived_on']), type: 'lc_shipments');

            return $s;
        });
    }

    public static function shipment(LcShipment $s): array
    {
        return ['id' => $s->id, 'lot_no' => (int) $s->lot_no, 'status' => $s->status, 'bl_number' => $s->bl_number, 'vessel' => $s->vessel, 'etd' => $s->etd?->toDateString(),
            'eta' => $s->eta?->toDateString(), 'arrived_on' => $s->arrived_on?->toDateString(), 'quantity' => $s->quantity !== null ? (string) $s->quantity : null,
            'value_foreign' => $s->value_foreign !== null ? (string) $s->value_foreign : null,
            'purchase_bill_id' => DB::table('purchase_bills')->where('lc_shipment_id', $s->id)->where('status', 'posted')->value('id'), 'version' => (int) $s->lock_version];
    }

    // ---------------------------------------------------------------- amendments (M5; BR-041)

    public function amend(int $lcId, array $in, LcPostingService $postings): LcAmendment
    {
        return DB::transaction(function () use ($lcId, $in, $postings) {
            $lc = LetterOfCredit::whereKey($lcId)->lockForUpdate()->firstOrFail();
            if (! in_array($lc->status, ['opened', 'partly_shipped', 'documents_presented', 'payment_due'], true)) {
                throw new ApiProblem('invalid_transition', "{$lc->lc_number} is {$lc->status}; only an opened LC is amended (a draft is simply edited).");
            }
            $a = new LcAmendment(['letter_of_credit_id' => $lc->id, 'amendment_no' => (int) LcAmendment::where('letter_of_credit_id', $lc->id)->max('amendment_no') + 1,
                'requested_on' => $in['requested_on'], 'status' => 'requested', 'reason' => $in['reason'] ?? null, 'other_change' => $in['other_change'] ?? null,
                'bank_fee' => $in['bank_fee'] ?? '0']);
            $lineRows = $this->amendmentLineRows($lc, $in['lines'] ?? []);
            $a->save();
            foreach ($lineRows as $row) {
                DB::table('lc_amendment_lines')->insert($row + ['lc_amendment_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($in['changes'] ?? [] as $ch) {
                $old = $ch['field_key'] === 'other' ? null : self::fieldText($lc, $ch['field_key']);
                DB::table('lc_amendment_changes')->insert(['lc_amendment_id' => $a->id, 'field_key' => $ch['field_key'], 'old_value' => $old, 'new_value' => $ch['new_value']]);
            }
            if (Money::of($a->bank_fee)->isPositive()) {
                $postings->addCharge(['letter_of_credit_id' => $lc->id, 'charge_type' => 'amendment_fee', 'charge_date' => $in['requested_on'], 'amount' => (string) $a->bank_fee,
                    'status' => 'unpaid', 'lc_amendment_id' => $a->id, 'paid_to' => $lc->issuingBank?->name]);
            }
            $this->audit->record('amendment_requested', $lc, null, ['amendment_no' => $a->amendment_no] + ($lineRows ? ['item_changes' => count($lineRows)] : []), type: 'letters_of_credit');
            if (in_array($in['status'], ['issued', 'accepted'], true)) {
                $a = $this->advanceAmendment($a, 'issued', $in['requested_on']);
            }
            if ($in['status'] === 'accepted') {
                $a = $this->advanceAmendment($a, 'accepted', $in['requested_on']);
            }

            return $a->refresh();
        });
    }

    public function updateAmendment(int $id, array $in, int $version): LcAmendment
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $a = LcAmendment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $a->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $a->lock_version]);
            }

            return $this->advanceAmendment($a, $in['status'], $in['date']);
        });
    }

    private function advanceAmendment(LcAmendment $a, string $to, string $date): LcAmendment
    {
        $allowed = ['requested' => ['issued', 'refused'], 'issued' => ['accepted', 'refused']][$a->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new ApiProblem('invalid_transition', "Amendment {$a->amendment_no} is {$a->status}; it cannot become {$to}.");
        }
        $patch = ['status' => $to] + ($to === 'issued' ? ['issued_on' => $date] : []) + ($to === 'accepted' ? ['accepted_on' => $date] : []);
        $from = $a->status;
        $a->forceFill($patch)->save();
        $this->audit->record($to, $a, ['status' => $from], $patch, type: 'lc_amendments');
        if ($to === 'accepted') {
            $lc = LetterOfCredit::whereKey($a->letter_of_credit_id)->lockForUpdate()->firstOrFail();
            $old = [];
            $new = [];
            foreach (DB::table('lc_amendment_changes')->where('lc_amendment_id', $a->id)->where('field_key', '!=', 'other')->get() as $ch) {
                $old[$ch->field_key] = self::fieldText($lc, $ch->field_key);
                $lc->{$ch->field_key} = $ch->new_value;
                $new[$ch->field_key] = $ch->new_value;
            }
            $lc->save();
            // PEP-487: item changes go to the LC's items (the linked order's lines), checked again under the order lock.
            $rows = DB::table('lc_amendment_lines')->where('lc_amendment_id', $a->id)->orderBy('id')->get()->values();
            if ($rows->isNotEmpty()) {
                $po = $lc->purchase_order_id ? PurchaseOrder::find($lc->purchase_order_id) : null;
                if (! $po) {
                    throw new ApiProblem('invalid_transition', "{$lc->lc_number} has no items any more; refuse amendment {$a->amendment_no} instead.");
                }
                $added = $this->orders->amendLines($po, $rows->map(fn ($r) => ['action' => $r->action, 'purchase_order_line_id' => $r->purchase_order_line_id, 'product_id' => $r->product_id,
                    'unit_id' => $r->unit_id, 'quantity' => $r->quantity, 'unit_cost' => $r->unit_cost, 'description' => $r->description])->all(), "{$lc->lc_number} amendment {$a->amendment_no}");
                foreach ($added as $i => $lineId) {
                    DB::table('lc_amendment_lines')->where('id', $rows[$i]->id)->update(['purchase_order_line_id' => $lineId, 'updated_at' => now()]);
                }
                $new['item_changes'] = $rows->map(fn ($r) => array_filter(['action' => $r->action, 'product_id' => $r->product_id, 'quantity' => $r->quantity, 'unit_cost' => $r->unit_cost],
                    fn ($v) => $v !== null))->all();
            }
            $this->audit->record('amended', $lc, $old, $new + ['amendment_no' => $a->amendment_no], type: 'letters_of_credit');
        }

        return $a->refresh();
    }

    private static function fieldText(LetterOfCredit $lc, string $key): ?string
    {
        $v = $lc->{$key};

        return $v === null ? null : ($v instanceof \DateTimeInterface ? CarbonImmutable::parse($v)->toDateString() : (string) $v);
    }

    public static function amendment(LcAmendment $a): array
    {
        return ['id' => $a->id, 'letter_of_credit_id' => $a->letter_of_credit_id, 'amendment_no' => (int) $a->amendment_no, 'status' => $a->status,
            'requested_on' => $a->requested_on?->toDateString(), 'issued_on' => $a->issued_on?->toDateString(), 'accepted_on' => $a->accepted_on?->toDateString(),
            'changes' => DB::table('lc_amendment_changes')->where('lc_amendment_id', $a->id)->orderBy('id')->get(['field_key', 'old_value', 'new_value'])->map(fn ($c) => (array) $c)->all(),
            'lines' => DB::table('lc_amendment_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
                ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(l.unit_id, p.unit_id)'))->where('l.lc_amendment_id', $a->id)->orderBy('l.id')
                ->get(['l.action', 'l.purchase_order_line_id', 'l.product_id', 'p.code', 'p.name', 'u.name as unit', 'l.old_quantity', 'l.quantity', 'l.old_unit_cost', 'l.unit_cost'])
                ->map(fn ($r) => ['action' => $r->action, 'purchase_order_line_id' => $r->purchase_order_line_id, 'product_id' => $r->product_id, 'product_code' => $r->code,
                    'product_name' => $r->name, 'unit' => (string) $r->unit, 'old_quantity' => $r->old_quantity === null ? null : (string) Quantity::of($r->old_quantity),
                    'quantity' => $r->quantity === null ? null : (string) Quantity::of($r->quantity),
                    'old_unit_cost' => $r->old_unit_cost === null ? null : (string) UnitCost::of($r->old_unit_cost),
                    'unit_cost' => $r->unit_cost === null ? null : (string) UnitCost::of($r->unit_cost)])->all(),
            'bank_fee' => (string) Money::of((string) $a->bank_fee), 'reason' => $a->reason, 'version' => (int) $a->lock_version];
    }

    private function assertNotFinal(LetterOfCredit $lc): void
    {
        if (in_array($lc->status, ['closed', 'cancelled'], true)) {
            throw new ApiProblem('invalid_transition', "{$lc->lc_number} is {$lc->status} and read-only".($lc->status === 'closed' ? '; reopen it first.' : '.'));
        }
    }

    // ---------------------------------------------------------------- the LC as the API shows it

    /** LetterOfCredit (05 schema); `$full` adds documents, lots, charges, payments, amendments and the money position (M4). */
    public function resource(LetterOfCredit $lc, bool $full = true): array
    {
        $lc->loadMissing('party', 'issuingBank', 'purchaseOrder');
        $paid = LcPostingService::paidForeign($lc->id);
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Dhaka')->toDateString());
        $days = $lc->expiry_date ? (int) $today->diffInDays(CarbonImmutable::parse($lc->expiry_date->toDateString()), false) : null;
        $margin = Money::of($lc->amount)->toBigDecimal()->multipliedBy($lc->planning_rate->toBigDecimal())->multipliedBy((string) $lc->margin_pct)->dividedBy(100, 2, RoundingMode::HalfUp);
        $out = [
            'id' => $lc->id, 'lc_number' => $lc->lc_number, 'status' => $lc->status, 'party' => ['id' => $lc->party->id, 'code' => $lc->party->code, 'name' => $lc->party->name],
            'purchase_order_id' => $lc->purchase_order_id ? (int) $lc->purchase_order_id : null,
            'purchase_order_number' => $lc->purchaseOrder?->number,
            'items_source' => $lc->purchaseOrder ? ($lc->items_entered ? 'entered' : 'purchase_order') : 'none',
            'lines_total' => $lc->purchaseOrder ? (string) ($lc->purchaseOrder->total_foreign ?? $lc->purchaseOrder->total) : null,
            'warehouse_id' => $lc->purchaseOrder?->warehouse_id,
            'quantity' => $lc->quantity !== null ? (string) $lc->quantity : null, 'quantity_unit' => $lc->quantity_unit,
            'goods_description' => $lc->goods_description, 'currency_code' => $lc->currency_code, 'amount' => (string) $lc->amount, 'tolerance_pct' => number_format((float) $lc->tolerance_pct, 4, '.', ''),
            'planning_rate' => (string) $lc->planning_rate,
            'amount_bdt_planned' => (string) Money::of($lc->amount->toBigDecimal()->multipliedBy($lc->planning_rate->toBigDecimal())),
            'paid_foreign' => (string) $paid, 'outstanding_foreign' => (string) Money::of($lc->amount)->minus($paid),
            'tenor' => $lc->tenor, 'usance_days' => $lc->usance_days !== null ? (int) $lc->usance_days : null, 'incoterm' => $lc->incoterm,
            'issuing_bank' => $lc->issuingBank ? (new MoneyAccountResource($lc->issuingBank))->resolve(request()) : null, 'bank_reference' => $lc->bank_reference,
            'opening_status' => $lc->opening_status, 'opened_on' => $lc->opened_on?->toDateString(), 'latest_shipment_date' => $lc->latest_shipment_date?->toDateString(),
            'expiry_date' => $lc->expiry_date?->toDateString(), 'days_to_expiry' => in_array($lc->status, ['closed', 'cancelled'], true) ? null : $days,
            'maturity_date' => $lc->maturity_date?->toDateString(), 'margin_pct' => number_format((float) $lc->margin_pct, 4, '.', ''), 'margin_amount' => (string) Money::of($margin),
            'flags' => $this->flags($lc, $days), 'milestones' => $this->milestones($lc),
            'created_at' => $lc->created_at?->toIso8601String(), 'created_by' => $lc->created_by ? ['id' => $lc->created_by, 'name' => (string) DB::table('users')->where('id', $lc->created_by)->value('name')] : null,
            'updated_at' => $lc->updated_at?->toIso8601String(), 'version' => (int) $lc->lock_version,
        ];
        if ($full) {
            $out += [
                'lines' => self::lines($lc->purchaseOrder),
                'documents' => $lc->documents()->get()->map(fn ($d) => self::document($d))->all(),
                'shipments' => $lc->shipments()->get()->map(fn ($s) => self::shipment($s))->all(),
                'charges' => $lc->charges()->get()->map(fn ($c) => LcPostingService::charge($c, $lc->lc_number))->all(),
                'payments' => $lc->payments()->get()->map(fn ($p) => LcPostingService::payment($p))->all(),
                'amendments' => $lc->amendments()->get()->map(fn ($a) => self::amendment($a))->all(),
                'money_position' => LcPostingService::position($lc),
            ];
        }

        return $out;
    }

    private function flags(LetterOfCredit $lc, ?int $days): array
    {
        if (in_array($lc->status, ['closed', 'cancelled', 'draft'], true)) {
            return [];
        }
        $flags = [];
        if ($days !== null && $days <= 15) {
            $flags[] = ['code' => 'LC_EXPIRING', 'message' => $days < 0 ? 'Expired '.abs($days).' days ago' : "Expires in {$days} days"];
        }
        $docs = DB::table('lc_documents')->where('letter_of_credit_id', $lc->id)->whereIn('status', ['missing', 'discrepant'])->count();
        if ($docs > 0) {
            $flags[] = ['code' => 'LC_DOCUMENTS_MISSING', 'message' => "{$docs} documents missing or discrepant"];
        }
        $unpaid = DB::table('lc_charges')->where('letter_of_credit_id', $lc->id)->where('status', 'unpaid')->where('posting_status', 'posted')->count();
        if ($unpaid > 0) {
            $flags[] = ['code' => 'LC_UNPAID_CHARGES', 'message' => "{$unpaid} charges not paid yet"];
        }
        array_push($flags, ...app(LcFinanceService::class)->limitFlags($lc));

        return $flags;
    }

    private function milestones(LetterOfCredit $lc): array
    {
        $firstShip = DB::table('lc_shipments')->where('letter_of_credit_id', $lc->id)->whereNotNull('shipped_on')->min('shipped_on');
        $arrived = DB::table('lc_shipments')->where('letter_of_credit_id', $lc->id)->whereNotNull('arrived_on')->min('arrived_on');
        $paid = DB::table('lc_payments')->where('letter_of_credit_id', $lc->id)->where('status', 'posted')->max('payment_date');
        $order = ['draft' => 0, 'awaiting_bank' => 1, 'opened' => 2, 'partly_shipped' => 3, 'documents_presented' => 4, 'payment_due' => 5, 'closed' => 6, 'expired' => 6, 'cancelled' => -1];
        $at = $order[$lc->status] ?? 0;

        return [
            ['key' => 'applied', 'label' => 'Applied', 'date' => $lc->application_date?->toDateString(), 'done' => $at >= 1],
            ['key' => 'opened', 'label' => 'Opened by the bank', 'date' => $lc->opened_on?->toDateString(), 'done' => $at >= 2],
            ['key' => 'shipped', 'label' => 'Shipped', 'date' => $firstShip ? substr((string) $firstShip, 0, 10) : null, 'done' => $at >= 3 || (bool) $firstShip],
            ['key' => 'arrived', 'label' => 'Arrived', 'date' => $arrived ? substr((string) $arrived, 0, 10) : null, 'done' => (bool) $arrived],
            ['key' => 'documents', 'label' => 'Documents presented', 'date' => null, 'done' => $at >= 4],
            ['key' => 'paid', 'label' => 'Paid', 'date' => $paid ? substr((string) $paid, 0, 10) : null, 'done' => (bool) $paid],
            ['key' => 'closed', 'label' => $lc->status === 'expired' ? 'Expired' : 'Closed', 'date' => $lc->closed_on?->toDateString(), 'done' => $at >= 6],
        ];
    }
}
