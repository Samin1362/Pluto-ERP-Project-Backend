<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\UploadedFile;

/**
 * POST /stock-adjustments/count-sheet (D10; backlog P11.1-BE-02): reads a count sheet — a CSV with a product code (or
 * barcode) column and a counted quantity column, header optional — and matches the rows to stock-tracked products.
 * Returns CountSheetPreview: the matched lines and the codes not found. Nothing is saved; the user reviews the lines in
 * D10 and saves the draft, which reads the system quantities. A row with a bad quantity refuses the file (422, by row).
 */
final class CountSheetReader
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 2000;

    private const CODE_HEADERS = ['code', 'product_code', 'barcode', 'sku'];

    private const QTY_HEADERS = ['counted_qty', 'counted', 'quantity', 'qty', 'count'];

    /** @return array{lines: list<array<string, mixed>>, unknown_codes: list<string>} */
    public function read(UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiProblem('file_too_large', 'The count sheet is larger than 2 MB.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw new ApiProblem('file_type_not_allowed', 'Upload the count sheet as a CSV file (code, counted quantity).');
        }
        $rows = $this->rows((string) file_get_contents($file->getRealPath()));
        [$codeCol, $qtyCol, $start] = $this->columns($rows[0] ?? []);

        $counts = [];
        $errors = [];
        foreach (array_slice($rows, $start, self::MAX_ROWS, true) as $i => $r) {
            $code = trim((string) ($r[$codeCol] ?? ''));
            $qty = str_replace(',', '', trim((string) ($r[$qtyCol] ?? '')));
            if ($code === '' && $qty === '') {
                continue;
            }
            if (! preg_match('/^\d{1,15}(\.\d{1,3})?$/', $qty)) {
                $errors[] = 'Row '.($i + 1).": \"{$qty}\" is not a counted quantity (0 or more, up to 3 decimals)";

                continue;
            }
            $counts[] = ['row' => $i + 1, 'code' => $code, 'qty' => $qty];
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', 'Some rows of the count sheet could not be read.', errors: ['file' => array_slice($errors, 0, 20)]);
        }
        $codes = array_values(array_unique(array_column($counts, 'code')));
        $products = Product::with('unit')->where('track_stock', true)->where(fn ($q) => $q->whereIn('code', $codes)->orWhereIn('barcode', $codes))->get();
        $byCode = $products->keyBy(fn ($p) => strtoupper($p->code));
        $byBarcode = $products->filter(fn ($p) => $p->barcode)->keyBy(fn ($p) => strtoupper($p->barcode));

        $lines = [];
        $unknown = [];
        foreach ($counts as $c) {
            $p = $byCode[strtoupper($c['code'])] ?? $byBarcode[strtoupper($c['code'])] ?? null;
            if (! $p) {
                $unknown[] = $c['code'];

                continue;
            }
            $lines[] = ['row' => $c['row'], 'product_id' => $p->id, 'product_code' => $p->code, 'product_name' => $p->name, 'unit' => $p->unit?->name,
                'counted_qty' => $c['qty'], 'is_active' => (bool) $p->is_active];
        }

        return ['lines' => $lines, 'unknown_codes' => array_values(array_unique($unknown))];
    }

    /** @return list<list<string>> */
    private function rows(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // Excel's UTF-8 BOM
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (trim($line) !== '') {
                $out[] = str_getcsv($line, str_contains($line, ';') && ! str_contains($line, ',') ? ';' : ',', '"', '');
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} code column, quantity column, first data row */
    private function columns(array $first): array
    {
        $head = array_map(fn ($h) => strtolower(str_replace(' ', '_', trim((string) $h))), $first);
        $code = array_values(array_intersect(self::CODE_HEADERS, $head))[0] ?? null;
        $qty = array_values(array_intersect(self::QTY_HEADERS, $head))[0] ?? null;
        if ($code !== null && $qty !== null) {
            return [array_search($code, $head, true), array_search($qty, $head, true), 1];
        }

        return [0, 1, 0]; // no header: code, quantity
    }
}
