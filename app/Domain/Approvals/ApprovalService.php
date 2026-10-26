<?php

namespace App\Domain\Approvals;

use App\Domain\Shared\Money;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Approval requests (BR-007, R-22; backlog P7.1-BE-04/05). A voucher above the submitter's role limit, or one sent
 * for approval, gets one pending request naming the permission an approver needs (accounting.approve for journals,
 * banking.approve for cash vouchers). Deciding requests (approve/reject, notifications) is P7.1-BE-05.
 */
final class ApprovalService
{
    /** Cash vouchers are approved by banking.approve; journals by accounting.approve. */
    public static function permissionFor(string $entryType): string
    {
        return $entryType === 'journal' ? 'accounting.approve' : 'banking.approve';
    }

    public function request(JournalEntry $entry, Money $amount): int
    {
        $this->cancelPending($entry);

        return DB::table('approval_requests')->insertGetId(['company_id' => $entry->company_id, 'document_type' => 'journal_entries', 'document_id' => $entry->id,
            'amount' => (string) $amount, 'required_permission' => self::permissionFor($entry->entry_type), 'status' => 'pending',
            'requested_by' => auth()->id(), 'requested_at' => now(), 'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A voucher that is edited or deleted withdraws its open request. */
    public function cancelPending(JournalEntry $entry): void
    {
        DB::table('approval_requests')->where('document_type', 'journal_entries')->where('document_id', $entry->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_by' => auth()->id(), 'updated_at' => now()]);
    }
}
