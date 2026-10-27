<?php

namespace App\Support\Reports;

use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of a ReportData payload (05 schema; backlog P9.1-BE-01): `format=xlsx|csv` streams the columns and rows with
 * openspout (amounts as numbers, the label indented by the row's depth, the totals row last); `pdf` renders the same
 * table on A4 with dompdf. Only presentation happens here: every amount is the report's own decimal string.
 */
final class ReportExporter
{
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    public function __construct(private readonly CurrentCompany $current) {}

    public function download(array $report, string $format, string $name): Response
    {
        $file = $name.'-'.str_replace('-', '', (string) ($report['period']['as_of'] ?? $report['period']['to'] ?? now('Asia/Dhaka')->toDateString())).'.'.$format;
        if ($format === 'pdf') {
            return response($this->pdf($report), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$file}\""]);
        }

        return new StreamedResponse(function () use ($report, $format) {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');
            $bold = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues([$report['title']], $bold));
            $writer->addRow(Row::fromValues([$this->periodText($report)]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_map(fn ($c) => $c['label'], $report['columns']), $bold));
            foreach ($this->tableRows($report) as [$cells, $strong]) {
                $values = array_map(fn ($v, $c) => $v !== null && in_array($c['type'], ['amount', 'qty', 'pct'], true) ? (float) $v : $v, $cells, $report['columns']);
                $writer->addRow(Row::fromValues($values, $strong ? $bold : null));
            }
            $writer->close();
        }, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$file}\"",
        ]);
    }

    /** @return list<array{0: list<string|null>, 1: bool}> one list of cell values per row (columns order) and whether it is bold */
    public function tableRows(array $report): array
    {
        $indent = collect($report['columns'])->firstWhere('type', 'text')['key'] ?? null; // the label column shows the row's depth
        $out = [];
        foreach ($report['rows'] as $row) {
            $cells = [];
            foreach ($report['columns'] as $c) {
                $v = $row[$c['key']] ?? null;
                if ($c['key'] === $indent) {
                    $v = str_repeat('  ', (int) ($row['depth'] ?? 0)).(string) $v;
                }
                $cells[] = $v === null ? null : (string) $v;
            }
            $out[] = [$cells, in_array($row['kind'] ?? '', ['heading', 'subtotal', 'total', 'closing'], true)];
        }
        if (! empty($report['totals'])) {
            $cells = [];
            foreach ($report['columns'] as $i => $c) {
                $cells[] = $i === 0 ? 'Total' : (isset($report['totals'][$c['key']]) ? (string) $report['totals'][$c['key']] : null);
            }
            $out[] = [$cells, true];
        }

        return $out;
    }

    private function pdf(array $report): string
    {
        $money = fn ($v) => $v === null || $v === '' ? '' : Taka::format(Money::of((string) $v), symbol: false);

        return Pdf::loadView('pdf.report', ['r' => $report, 'rows' => $this->tableRows($report), 'money' => $money, 'period' => $this->periodText($report),
            'company' => $this->current->company()])->setPaper('a4', count($report['columns']) > 6 ? 'landscape' : 'portrait')
            ->setOption('isFontSubsettingEnabled', true)->output();
    }

    private function periodText(array $report): string
    {
        $p = $report['period'];
        $d = fn ($x) => CarbonImmutable::parse($x)->format('j M Y');

        return isset($p['as_of']) ? 'As of '.$d($p['as_of']) : (isset($p['from'], $p['to']) ? $d($p['from']).' – '.$d($p['to']) : '');
    }
}
