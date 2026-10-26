<?php

namespace App\Queries;

use App\Models\JournalEntry;
use App\Models\Ledger;
use Illuminate\Database\Eloquent\Builder;

/** GET /vouchers (H8 voucher list and day book; 05 getVouchers; backlog P7.1-BE-04): every journal entry, newest first by default. */
final class VoucherQuery
{
    private const SORTS = ['date' => 'entry_date', 'number' => 'voucher_number', 'amount' => 'total_debit'];

    public function list(array $f): Builder
    {
        $q = JournalEntry::query()->with('createdBy');
        if (isset($f['entry_type'])) {
            $q->whereIn('entry_type', explode(',', $f['entry_type']));
        }
        if (isset($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (isset($f['date_from'])) {
            $q->where('entry_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->where('entry_date', '<=', $f['date_to']);
        }
        if (isset($f['ledger_id'])) {
            $q->whereExists(fn ($w) => $w->selectRaw('1')->from('journal_lines')->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
                ->where('journal_lines.ledger_id', $f['ledger_id']));
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('voucher_number', 'like', $term)->orWhere('narration', 'like', $term)->orWhere('reference', 'like', $term));
        }
        $sort = $f['sort'] ?? '-date';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return $q->orderBy(self::SORTS[ltrim($sort, '-')], $direction)->orderBy('id', $direction);
    }

    /**
     * TemplateList (05 getVoucherTemplates; H9): ready-made journals whose ledgers exist in this company. Each line
     * names its ledger and side; the user enters the amounts.
     */
    public static function templates(): array
    {
        $defs = [
            ['key' => 'depreciation', 'name' => 'Monthly depreciation', 'description' => 'Depreciation for the month on fixed assets',
                'lines' => [['6501', 'debit'], ['1509', 'credit']]],
            ['key' => 'salary_accrual', 'name' => 'Salaries due at month end', 'description' => 'Salaries earned but not yet paid',
                'lines' => [['6001', 'debit'], ['2201', 'credit']]],
            ['key' => 'expense_accrual', 'name' => 'Expense accrual', 'description' => 'An expense of this month to be paid later (rent, electricity)',
                'lines' => [['6110', 'debit'], ['2202', 'credit']]],
            ['key' => 'prepaid_expense', 'name' => 'Advance paid used up', 'description' => 'Move an advance or deposit to the expense it paid for',
                'lines' => [['6101', 'debit'], ['1401', 'credit']]],
            ['key' => 'reclassify', 'name' => 'Reclassify', 'description' => 'Move an amount posted to the wrong ledger to the right one', 'lines' => []],
        ];
        $ledgers = Ledger::whereIn('code', collect($defs)->flatMap(fn ($d) => array_column($d['lines'], 0))->unique()->all())
            ->where('is_active', true)->get(['id', 'code', 'name'])->keyBy('code');

        return ['templates' => array_values(array_filter(array_map(function ($d) use ($ledgers) {
            $lines = [];
            foreach ($d['lines'] as [$code, $side]) {
                if (! isset($ledgers[$code])) {
                    return null; // the company removed or deactivated a ledger it needs
                }
                $lines[] = ['ledger_id' => $ledgers[$code]->id, 'ledger_code' => $code, 'ledger_name' => $ledgers[$code]->name, 'side' => $side];
            }

            return ['key' => $d['key'], 'name' => $d['name'], 'description' => $d['description'], 'entry_type' => 'journal', 'lines' => $lines];
        }, $defs)))];
    }
}
