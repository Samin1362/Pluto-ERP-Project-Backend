<?php

namespace App\Domain\Approvals;

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounting\VoucherService;
use App\Domain\Shared\AuditLogger;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Approve or reject a pending request (L4 drawer; 05 approvals; BR-007, BR-008; backlog P7.1-BE-05). Only a holder of
 * the request's required_permission decides, and never the person who submitted it (self_approval_forbidden).
 * Approving posts the voucher in the same transaction — open period, balanced, funds (BR-001, BR-004, BR-031); a
 * rejection needs a comment and sends the voucher back to its author as rejected (editable again). Both notify the
 * requester and are audited.
 */
final class ApprovalDecisionService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly PermissionResolver $permissions,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(int $id, CompanyUser $member, ?string $comment): void
    {
        DB::transaction(function () use ($id, $member, $comment) {
            [$request, $entry] = $this->lock($id, $member);
            $entry->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $member->user_id])->save(); // before posting: posted rows are frozen
            try {
                $this->vouchers->postOrSubmit($entry, true, null, [], approved: true);
            } catch (ApiProblem $e) {
                if ($e->errorCode !== 'insufficient_funds') {
                    throw $e;
                }
                throw new ApiProblem('validation_failed', $e->detail, errors: ['amount' => [$e->detail]]); // approve declares no insufficient_funds
            }
            $this->decide($request, 'approved', $member, $comment);
            $this->notifications->notify($request->company_id, [(int) $request->requested_by], 'approval_approved', "{$entry->voucher_number} was approved and posted",
                $comment, 'approvals', 'journal_entries', $entry->id);
            $this->audit->record('approved', $entry, ['status' => 'submitted'], ['status' => 'posted', 'comment' => $comment], type: 'journal_entries');
        }, attempts: 2);
    }

    public function reject(int $id, CompanyUser $member, ?string $comment): void
    {
        if (trim((string) $comment) === '') {
            throw new ApiProblem('comment_required', errors: ['comment' => ['Say why it is rejected so the author can fix it']]);
        }
        DB::transaction(function () use ($id, $member, $comment) {
            [$request, $entry] = $this->lock($id, $member);
            $entry->forceFill(['status' => 'rejected'])->save();
            $this->decide($request, 'rejected', $member, $comment);
            $this->notifications->notify($request->company_id, [(int) $request->requested_by], 'approval_rejected', "{$entry->voucher_number} was rejected",
                $comment, 'approvals', 'journal_entries', $entry->id);
            $this->audit->record('rejected', $entry, ['status' => 'submitted'], ['status' => 'rejected', 'comment' => $comment], type: 'journal_entries');
        });
    }

    /** Whether the member may see the request: its requester, or a holder of its permission. */
    public function canSee(object $request, CompanyUser $member): bool
    {
        return (int) $request->requested_by === (int) $member->user_id || $this->permissions->allows($member, $request->required_permission);
    }

    /** @return array{0: object, 1: JournalEntry} */
    private function lock(int $id, CompanyUser $member): array
    {
        $request = DB::table('approval_requests')->where('id', $id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
        if ((int) $request->requested_by === (int) $member->user_id) {
            throw new ApiProblem('self_approval_forbidden', 'You submitted this voucher; another approver must decide it (BR-008).');
        }
        if (! $this->permissions->allows($member, $request->required_permission)) {
            throw new ApiProblem('permission_denied', "Deciding this request needs {$request->required_permission}.");
        }
        $entry = JournalEntry::whereKey($request->document_id)->lockForUpdate()->firstOrFail();
        if ($request->status !== 'pending' || $entry->status !== 'submitted') {
            throw new ApiProblem('not_draft', "This request is already {$request->status}.");
        }

        return [$request, $entry];
    }

    private function decide(object $request, string $status, CompanyUser $member, ?string $comment): void
    {
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => $status, 'decided_by' => $member->user_id, 'decided_at' => now(),
            'comment' => $comment, 'updated_by' => $member->user_id, 'updated_at' => now()]);
    }
}
