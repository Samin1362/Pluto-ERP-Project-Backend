<?php

namespace App\Domain\Approvals;

use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approval requests (BR-007, R-22; backlog P7.1-BE-04/05). A voucher above the submitter's role limit, or one sent
 * for approval, gets one pending request naming the permission an approver needs (accounting.approve for journals,
 * banking.approve for cash vouchers); its approvers are notified. Decisions: ApprovalDecisionService.
 */
final class ApprovalService
{
    /** Journals: accounting.approve; supplier payments: purchases.approve (05 approvals); other cash vouchers: banking.approve. */
    public static function permissionFor(string $entryType, ?int $entryId = null): string
    {
        if ($entryType === 'journal') {
            return 'accounting.approve';
        }
        $toSupplier = $entryType === 'payment' && $entryId && DB::table('payment_details as p')->join('parties as y', 'y.id', '=', 'p.party_id')
            ->where('p.journal_entry_id', $entryId)->where('y.party_type', 'supplier')->exists();

        return $toSupplier ? 'purchases.approve' : 'banking.approve';
    }

    public function __construct(private readonly NotificationService $notifications) {}

    /** Opens a pending request and tells every member who can decide it (L3 bell), never the requester. */
    public function request(JournalEntry $entry, Money $amount): int
    {
        $this->cancelPending($entry);
        $permission = self::permissionFor($entry->entry_type, $entry->id);
        $id = DB::table('approval_requests')->insertGetId(['company_id' => $entry->company_id, 'document_type' => 'journal_entries', 'document_id' => $entry->id,
            'amount' => (string) $amount, 'required_permission' => $permission, 'status' => 'pending',
            'requested_by' => auth()->id(), 'requested_at' => now(), 'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->notifications->notify($entry->company_id, $this->notifications->holders($permission, auth()->id()), 'approval_requested',
            "{$entry->voucher_number} waits for your approval", 'Tk '.Taka::format($amount, symbol: false).($entry->narration ? " · {$entry->narration}" : ''), 'approvals', 'approval_requests', $id);

        return $id;
    }

    /** The voucher's latest request, as ApprovalRequest, or null. */
    public static function latestFor(JournalEntry $entry): ?array
    {
        $id = DB::table('approval_requests')->where('document_type', 'journal_entries')->where('document_id', $entry->id)->orderByDesc('id')->value('id');

        return $id ? self::present((int) $id) : null;
    }

    /**
     * ApprovalRequest (05 schema): the voucher's number, the requester's role limit for that kind of voucher, and
     * `facts` for the approval drawer (type, date, narration, lines).
     */
    public static function present(int $id): array
    {
        $r = DB::table('approval_requests as r')->join('journal_entries as e', 'e.id', '=', 'r.document_id')
            ->leftJoin('users as q', 'q.id', '=', 'r.requested_by')->leftJoin('users as d', 'd.id', '=', 'r.decided_by')
            ->where('r.id', $id)->first(['r.*', 'e.voucher_number', 'e.entry_type', 'e.entry_date', 'e.narration', 'e.company_id as entry_company',
                'q.name as requester', 'd.name as decider']);
        $column = $r->entry_type === 'journal' ? 'journal_approval_limit' : 'payment_approval_limit';
        $limit = DB::table('company_users as m')->join('roles as o', 'o.id', '=', 'm.role_id')->where('m.company_id', $r->company_id)
            ->where('m.user_id', $r->requested_by)->value("o.{$column}");
        $lines = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $r->document_id)->orderBy('l.line_no')
            ->get(['g.code', 'g.name', 'l.debit', 'l.credit'])->map(fn ($l) => ['ledger' => "{$l->code} {$l->name}", 'debit' => $l->debit, 'credit' => $l->credit])->all();

        return ['id' => $r->id, 'document_type' => $r->document_type, 'document_id' => $r->document_id, 'document_number' => $r->voucher_number,
            'amount' => (string) Money::of((string) $r->amount), 'limit' => $limit !== null ? (string) Money::of((string) $limit) : null,
            'required_permission' => $r->required_permission, 'status' => $r->status,
            'requested_by' => ['id' => $r->requested_by, 'name' => $r->requester], 'requested_at' => Carbon::parse($r->requested_at)->toIso8601String(),
            'decided_by' => $r->decided_by ? ['id' => $r->decided_by, 'name' => $r->decider] : null,
            'decided_at' => $r->decided_at ? Carbon::parse($r->decided_at)->toIso8601String() : null, 'comment' => $r->comment,
            'facts' => ['entry_type' => $r->entry_type, 'entry_date' => (string) $r->entry_date, 'narration' => $r->narration, 'lines' => $lines]];
    }

    /** A voucher that is edited or deleted withdraws its open request. */
    public function cancelPending(JournalEntry $entry): void
    {
        DB::table('approval_requests')->where('document_type', 'journal_entries')->where('document_id', $entry->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_by' => auth()->id(), 'updated_at' => now()]);
    }
}
