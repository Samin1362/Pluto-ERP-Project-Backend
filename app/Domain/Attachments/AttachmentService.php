<?php

namespace App\Domain\Attachments;

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Shared\AuditLogger;
use App\Models\Attachment;
use App\Models\CompanyUser;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Files on records (FR-066; backlog P13.1-BE-01; BR-051, NFR-016). A file must be a JPG, PNG or PDF (the content is
 * sniffed with finfo, never trusted from the name or the browser: file_type_not_allowed, 415) of at most 10 MB
 * (file_too_large, 413). It is kept on the private disk under company/{id}/{yyyy}/{mm}/{uuid}.{ext} with its SHA-256.
 * Who may attach, read or remove follows the record the file belongs to: <module>.edit, or <module>.create while the
 * record is a draft; reading needs <module>.view. A voucher's module is banking for cash vouchers (payment, receipt,
 * contra, expense, income) and accounting for the rest. An upload for a voucher may come before the voucher exists
 * (attachable_id omitted: a pending file of an income or expense being written, I3/I6); POST /ie-entries links it, and
 * pending files older than a day are pruned. Files of a posted voucher in a locked period stay (period_locked).
 */
final class AttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const TYPES = ['sales_invoice', 'purchase_bill', 'voucher', 'lc_document', 'letter_of_credit', 'stock_adjustment', 'party'];

    private const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    /** type => [table, module or null (voucher: by entry type), status column or null] */
    private const OWNERS = [
        'sales_invoice' => ['sales_invoices', 'sales', 'status'], 'purchase_bill' => ['purchase_bills', 'purchases', 'status'],
        'voucher' => ['journal_entries', null, 'status'], 'lc_document' => ['lc_documents', 'lc', null], 'letter_of_credit' => ['letters_of_credit', 'lc', 'status'],
        'stock_adjustment' => ['stock_adjustments', 'inventory', 'status'], 'party' => ['parties', 'parties', null],
    ];

    private const CASH_VOUCHERS = ['payment', 'receipt', 'contra', 'expense', 'income'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
        private readonly PeriodGuard $periods,
        private readonly AuditLogger $audit,
    ) {}

    public function store(UploadedFile $file, string $type, ?int $id, CompanyUser $membership): Attachment
    {
        if ($id === null && $type !== 'voucher') {
            throw new ApiProblem('validation_failed', errors: ['attachable_id' => ['Choose the record the file belongs to']]);
        }
        $id === null ? $this->authorizePending($membership) : $this->authorize($type, $id, 'edit', $membership);
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiProblem('file_too_large', 'The file is '.number_format($file->getSize() / 1048576, 1).' MB; files may be up to 10 MB (BR-051).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
        if (! isset(self::MIME[$mime])) {
            throw new ApiProblem('file_type_not_allowed', "{$file->getClientOriginalName()} is not a JPG, PNG or PDF (BR-051).");
        }
        $now = CarbonImmutable::now('Asia/Dhaka');
        $path = sprintf('company/%d/%s/%s/%s.%s', $this->current->id(), $now->format('Y'), $now->format('m'), Str::uuid(), self::MIME[$mime]);
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($file, $type, $id, $mime, $path) {
            $a = Attachment::create(['attachable_type' => $type, 'attachable_id' => $id ?? 0, 'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'storage_disk' => 'local', 'storage_path' => $path,
                'sha256' => hash_file('sha256', $file->getRealPath())]);
            $this->audit->record('attached', $a, null, ['attachable_type' => $type, 'attachable_id' => $id, 'original_name' => $a->original_name, 'size_bytes' => $a->size_bytes],
                type: 'attachments');

            return $a;
        });
    }

    /** A short-lived (5 minutes) relative signed link to the file, so it also works through the Vercel proxy. */
    public function signedUrl(Attachment $a, CompanyUser $membership): array
    {
        $a->isPending() ? $this->assertOwnPending($a) : $this->authorize($a->attachable_type, $a->attachable_id, 'view', $membership);
        $expires = now()->addMinutes(5);

        return ['url' => URL::temporarySignedRoute('attachments.file', $expires, ['id' => $a->id], absolute: false), 'expires_at' => $expires->toIso8601String()];
    }

    public function delete(Attachment $a, CompanyUser $membership): void
    {
        if ($a->isPending()) {
            $this->assertOwnPending($a);
        } else {
            $this->authorize($a->attachable_type, $a->attachable_id, 'edit', $membership);
            if ($a->attachable_type === 'voucher') {
                $entry = DB::table('journal_entries')->where('id', $a->attachable_id)->first(['status', 'entry_date']);
                if ($entry && $entry->status === 'posted') {
                    $this->periods->assertOpen($entry->entry_date); // period_locked: the books of a locked period keep their evidence
                }
            }
        }
        DB::transaction(function () use ($a) {
            $this->audit->record('detached', $a, ['attachable_type' => $a->attachable_type, 'attachable_id' => $a->attachable_id, 'original_name' => $a->original_name], null,
                type: 'attachments');
            $a->delete();
        });
        Storage::disk($a->storage_disk)->delete($a->storage_path);
    }

    /** The files of one record, newest first, for its Attachments tab. */
    public function listFor(string $type, int $id, CompanyUser $membership)
    {
        $this->authorize($type, $id, 'view', $membership);

        return Attachment::with('uploader')->where('attachable_type', $type)->where('attachable_id', $id)->orderByDesc('id')->get();
    }

    /**
     * Links pending uploads of the current user to a voucher just written (POST /ie-entries attachment_ids). Each id
     * must be the user's own pending file in this company.
     *
     * @param  list<int>  $ids
     */
    public function linkPending(array $ids, int $voucherId): void
    {
        if ($ids === []) {
            return;
        }
        $found = Attachment::whereIn('id', $ids)->where('attachable_type', 'voucher')->where('attachable_id', 0)->where('created_by', auth()->id())->lockForUpdate()->get();
        if ($found->count() !== count(array_unique($ids))) {
            throw new ApiProblem('validation_failed', errors: ['attachment_ids' => ['Attach files you uploaded for this entry; one of them is not available']]);
        }
        Attachment::whereIn('id', $found->pluck('id'))->update(['attachable_id' => $voucherId, 'updated_by' => auth()->id(), 'updated_at' => now()]);
    }

    /** Daily: pending uploads nobody saved an entry with, older than a day. @return int files removed */
    public static function prunePending(): int
    {
        $old = Attachment::withoutGlobalScopes()->where('attachable_id', 0)->where('created_at', '<', now()->subDay())->get();
        foreach ($old as $a) {
            Storage::disk($a->storage_disk)->delete($a->storage_path);
            $a->delete();
        }

        return $old->count();
    }

    /** Throws not_found for a record of another company and permission_denied without the module's permission. */
    public function authorize(string $type, int $id, string $action, CompanyUser $membership): void
    {
        [$table, $module, $statusCol] = self::OWNERS[$type];
        $record = DB::table($table)->where('id', $id)->where('company_id', $this->current->id())->first();
        if (! $record) {
            throw new ApiProblem('not_found', 'The record this file belongs to was not found.');
        }
        $module ??= in_array($record->entry_type, self::CASH_VOUCHERS, true) ? 'banking' : 'accounting';
        $allowed = $this->permissions->allows($membership, "{$module}.{$action}")
            || ($action === 'edit' && $statusCol && ($record->{$statusCol} ?? null) === 'draft' && $this->permissions->allows($membership, "{$module}.create"));
        if (! $allowed) {
            throw new ApiProblem('permission_denied', "Your role does not include {$module}.{$action}.");
        }
    }

    private function authorizePending(CompanyUser $membership): void
    {
        if (! $this->permissions->allows($membership, 'banking.create')) {
            throw new ApiProblem('permission_denied', 'Your role does not include banking.create.');
        }
    }

    private function assertOwnPending(Attachment $a): void
    {
        if ((int) $a->created_by !== (int) auth()->id()) {
            throw new ApiProblem('not_found');
        }
    }
}
