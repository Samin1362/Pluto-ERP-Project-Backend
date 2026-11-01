<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One settlement of an invoice or bill by a party-ledger line (04 bill_allocations). Named as the source of the FX
 * entry an LC payment posts when it settles an import bill at another rate (06 §12.5): such entries never count as
 * advances.
 */
class BillAllocation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];
}
