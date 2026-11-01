<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** PATCH /company/settings (CompanySettingsUpdate; K3, K5, K6, K7, K14, K15). Base currency is not a setting (BR-035). */
class CompanySettingsRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['valuation_method', 'invoice_rounding', 'allow_negative_stock', 'require_approval', 'backdate_warning_days', 'prices_include_tax', 'show_tax_no_on_invoice', 'default_warehouse_id', 'default_payment_term_id', 'symbol_position', 'table_decimals', 'negative_format', 'number_grouping', 'date_format', 'week_start', 'print_language', 'invoice', 'require_two_factor', 'idle_timeout_minutes', 'backup_enabled', 'backup_retention_days'];
    }

    public function rules(): array
    {
        return [
            'valuation_method' => ['sometimes', Rule::in(['weighted_average'])],
            'invoice_rounding' => ['sometimes', Rule::in(['none', 'nearest_1', 'nearest_5'])],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'backdate_warning_days' => ['sometimes', 'integer', 'between:0,365'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'show_tax_no_on_invoice' => ['sometimes', 'boolean'],
            'default_warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'default_payment_term_id' => ['sometimes', 'nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'symbol_position' => ['sometimes', Rule::in(['before', 'after'])],
            'table_decimals' => ['sometimes', 'integer', Rule::in([0, 2])],
            'negative_format' => ['sometimes', Rule::in(['parentheses', 'minus'])],
            'number_grouping' => ['sometimes', Rule::in(['lakh', 'million'])],
            'date_format' => ['sometimes', Rule::in(['d M Y', 'd/m/Y', 'Y-m-d', 'd-m-Y'])],
            'week_start' => ['sometimes', Rule::in(['saturday', 'sunday', 'monday'])],
            'print_language' => ['sometimes', Rule::in(['en', 'bn', 'both'])],
            'invoice' => ['sometimes', 'array:template,accent_color,show_logo,show_words,show_bank,show_signature,footer_note'],
            'invoice.template' => ['sometimes', Rule::in(['classic', 'banded', 'minimal'])],
            'invoice.accent_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'invoice.show_logo' => ['sometimes', 'boolean'],
            'invoice.show_words' => ['sometimes', 'boolean'],
            'invoice.show_bank' => ['sometimes', 'boolean'],
            'invoice.show_signature' => ['sometimes', 'boolean'],
            'invoice.footer_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'require_two_factor' => ['sometimes', 'boolean'],
            'idle_timeout_minutes' => ['sometimes', 'nullable', 'integer', Rule::in([15, 30, 60, 120, 240])],
            'backup_enabled' => ['sometimes', 'boolean'],
            'backup_retention_days' => ['sometimes', 'integer', 'between:7,365'],
        ];
    }

    public function messages(): array
    {
        return [
            'valuation_method.in' => 'Weighted average is the only method in this release (FIFO comes later)',
            'invoice.accent_color.regex' => 'Use a colour like #0B6B5A',
            'idle_timeout_minutes.in' => 'Choose 15, 30, 60, 120 or 240 minutes',
        ];
    }
}
