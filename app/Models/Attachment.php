<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a record (FR-066; BR-051): metadata here, the bytes on the private disk under storage_path. attachable_type
 * is the contract's record type (sales_invoice, purchase_bill, voucher, …); attachable_id 0 marks a pending upload for
 * an income or expense being written (linked by POST /ie-entries attachment_ids).
 */
class Attachment extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['size_bytes' => 'integer', 'attachable_id' => 'integer'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->attachable_id === 0;
    }
}
