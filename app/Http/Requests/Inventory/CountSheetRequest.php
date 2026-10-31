<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;

/** FileUpload for POST /stock-adjustments/count-sheet (D10; backlog P11.1-BE-02): a CSV of product code and counted quantity. */
class CountSheetRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['file'];
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose the count sheet (CSV)', 'file.file' => 'Choose the count sheet (CSV)'];
    }
}
