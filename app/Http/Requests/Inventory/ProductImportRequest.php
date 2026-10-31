<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;

/** ProductImport (05 postProductsImport; D1 Import; backlog P11.1-BE-05): multipart `file` plus the `dry_run` query flag. */
class ProductImportRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['file'];
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file'], 'dry_run' => ['sometimes', 'in:true,false,1,0']];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose the product file (CSV or XLSX)', 'file.file' => 'Choose the product file (CSV or XLSX)'];
    }

    public function dryRun(): bool
    {
        return in_array((string) $this->query('dry_run', 'false'), ['true', '1'], true);
    }
}
