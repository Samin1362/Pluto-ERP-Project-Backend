<?php

namespace App\Domain\Shared;

use App\Models\NumberSequence;
use LogicException;

/**
 * Takes the next number of a series inside the caller's transaction (BR-006): the sequence row is locked
 * FOR UPDATE, so concurrent saves never get the same number, and a rolled-back save does not consume one.
 */
final class DocumentNumberService
{
    /** @return array{number: string, sequence: int} */
    public function next(string $documentType, ?int $fiscalYearId = null): array
    {
        $seq = NumberSequence::where('document_type', $documentType)
            ->when($fiscalYearId, fn ($q) => $q->where('fiscal_year_id', $fiscalYearId), fn ($q) => $q->whereNull('fiscal_year_id'))
            ->lockForUpdate()->first() ?? throw new LogicException("No number series for {$documentType}");
        $n = (int) $seq->next_number;
        $seq->next_number = $n + 1;
        $seq->save();

        return ['number' => $seq->prefix.str_pad((string) $n, (int) $seq->padding, '0', STR_PAD_LEFT), 'sequence' => $n];
    }
}
