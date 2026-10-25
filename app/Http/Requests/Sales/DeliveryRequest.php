<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;

/** DeliveryUpdate (05 patchSalesInvoicesIdDelivery; GAP-12; backlog P5.1-BE-04): challan, vehicle, delivery time. */
class DeliveryRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['challan_no', 'vehicle_no', 'delivered_at'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['challan_no', 'vehicle_no'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'challan_no' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vehicle_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'delivered_at' => ['sometimes', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/'],
        ];
    }

    public function messages(): array
    {
        return ['delivered_at.regex' => 'Enter the delivery time as an ISO 8601 date and time with its offset', 'delivered_at.date' => 'Enter a valid delivery time'];
    }
}
