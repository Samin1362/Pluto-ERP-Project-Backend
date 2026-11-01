<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/documents, PATCH /lc-documents/{id} (LcDocumentInput). */
class LcDocumentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'provided_by', 'copies', 'note', 'is_required', 'status', 'due_date', 'received_date', 'discrepancy_note', 'lc_shipment_id'];
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'provided_by' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:80'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_required' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['not_yet_due', 'pending', 'received', 'missing', 'discrepant', 'not_required'])],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'received_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'discrepancy_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lc_shipment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lc_shipments', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }
}
