<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompanySettings (05 schemas). Every member reads the formatting and control settings; the security and backup
 * fields are only included for members with settings.view ("fields restricted by permission are omitted").
 */
class CompanySettingsResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withAdminFields = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [
            'valuation_method' => $this->valuation_method,
            'invoice_rounding' => $this->invoice_rounding,
            'allow_negative_stock' => (bool) $this->allow_negative_stock,
            'require_approval' => (bool) $this->require_approval,
            'backdate_warning_days' => (int) $this->backdate_warning_days,
            'prices_include_tax' => (bool) $this->prices_include_tax,
            'show_tax_no_on_invoice' => (bool) $this->show_tax_no_on_invoice,
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_payment_term_id' => $this->default_payment_term_id,
            'symbol_position' => $this->symbol_position,
            'table_decimals' => (int) $this->table_decimals,
            'negative_format' => $this->negative_format,
            'number_grouping' => $this->number_grouping,
            'date_format' => $this->date_format,
            'week_start' => $this->week_start,
            'print_language' => $this->print_language,
            'invoice' => [
                'template' => $this->invoice_template,
                'accent_color' => $this->invoice_accent_color,
                'show_logo' => (bool) $this->invoice_show_logo,
                'show_words' => (bool) $this->invoice_show_words,
                'show_bank' => (bool) $this->invoice_show_bank,
                'show_signature' => (bool) $this->invoice_show_signature,
                'footer_note' => $this->invoice_footer_note,
            ],
            'require_two_factor' => (bool) $this->require_two_factor,
            'version' => (int) $this->lock_version,
        ];
        if ($this->withAdminFields) {
            $data['idle_timeout_minutes'] = $this->idle_timeout_minutes === null ? null : (int) $this->idle_timeout_minutes;
            $data['backup_enabled'] = (bool) $this->backup_enabled;
            $data['backup_retention_days'] = (int) $this->backup_retention_days;
        }

        return $data;
    }
}
