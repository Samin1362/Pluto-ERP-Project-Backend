<?php

namespace App\Domain\Parties;

use App\Mail\PartyStatementMail;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Queries\PartyAccountQuery;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Party statements (B8/C8; FR-024; backlog P6.1-BE-05): the ledger for a period with opening, movements and closing,
 * optionally the aging, as JSON (PartyStatement), an A4 PDF, or e-mailed to several parties at once (queued; an
 * export_jobs row records the run, parties without an e-mail are listed as skipped).
 */
final class PartyStatementService
{
    public function __construct(private readonly PartyAccountQuery $accounts, private readonly CurrentCompany $current) {}

    /** PartyStatement (05 schema). */
    public function build(Party $party, string $from, string $to, bool $aging): array
    {
        $l = $this->accounts->ledger($party, $from, $to);

        return [
            'statement_no' => 'ST-'.$party->code.'-'.str_replace('-', '', $to),
            'party' => ['id' => $party->id, 'code' => $party->code, 'name' => $party->name],
            'period' => $l['period'],
            'summary' => ['opening' => $l['opening'], 'opening_side' => $l['opening_side'], 'total_debit' => $l['total_debit'], 'total_credit' => $l['total_credit'],
                'closing' => $l['closing'], 'closing_side' => $l['closing_side'], 'party_type' => $party->party_type],
            'lines' => $l['lines'],
            'aging' => $aging ? $this->accounts->aging($party, $to)['buckets'] : null,
            'footer' => CompanySetting::value('invoice_footer_note'),
        ];
    }

    public function pdf(Party $party, string $from, string $to, bool $aging): string
    {
        return Pdf::loadView('pdf.party-statement', ['s' => $this->build($party, $from, $to, $aging), 'party' => $party, 'company' => $this->current->company()])
            ->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** @return array ExportJob (05 schema) */
    public function send(array $partyIds, string $from, string $to, bool $aging): array
    {
        $company = $this->current->company();
        $sent = [];
        $skipped = [];
        foreach (Party::whereIn('id', $partyIds)->orderBy('name')->get() as $party) {
            if (! $party->email) {
                $skipped[] = $party->code;

                continue;
            }
            Mail::to($party->email)->queue(new PartyStatementMail($party->name, $company->trading_name ?: $company->legal_name, $from, $to,
                base64_encode($this->pdf($party, $from, $to, $aging)), "statement-{$party->code}.pdf"));
            $sent[] = $party->code;
        }
        $id = DB::table('export_jobs')->insertGetId(['company_id' => $company->id, 'kind' => 'report', 'status' => 'complete', 'finished_at' => now(),
            'parameters' => json_encode(['report' => 'party_statements', 'date_from' => $from, 'date_to' => $to, 'include_aging' => $aging, 'sent' => $sent, 'skipped' => $skipped]),
            'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, 'kind' => 'report', 'status' => 'complete', 'download_url' => null, 'expires_at' => null,
            'finished_at' => CarbonImmutable::now()->toIso8601String(), 'error' => $skipped ? 'No e-mail on file for: '.implode(', ', $skipped) : null];
    }
}
