<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/shipments, PATCH /lc-shipments/{id} (LcShipmentInput). */
class LcShipmentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['lot_no', 'status', 'booking_ref', 'bl_number', 'vessel', 'port_of_loading', 'port_of_discharge', 'mode', 'etd', 'eta', 'shipped_on', 'arrived_on', 'quantity', 'value_foreign'];
    }

    public function rules(): array
    {
        return [
            'lot_no' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'status' => ['sometimes', Rule::in(['not_shipped', 'booking_confirmed', 'at_sea', 'arrived', 'arrived_cleared', 'not_shipped_expired'])],
            'booking_ref' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bl_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vessel' => ['sometimes', 'nullable', 'string', 'max:120'],
            'port_of_loading' => ['sometimes', 'nullable', 'string', 'max:120'],
            'port_of_discharge' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mode' => ['sometimes', Rule::in(['sea', 'air', 'road'])],
            'etd' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'eta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'shipped_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'arrived_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'quantity' => ['sometimes', 'nullable', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'value_foreign' => ['sometimes', 'nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }
}
