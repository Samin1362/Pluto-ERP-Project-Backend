@php
    /** @var \App\Models\SalesInvoice $invoice */
    $challan = $variant === 'challan';
    $accent = $settings?->invoice_accent_color ?: '#0b6b5e';
    $money = fn ($m) => \App\Domain\Shared\Taka::format(\App\Domain\Shared\Money::of((string) $m), symbol: false);
    $qty = fn ($q) => str_contains((string) $q, '.') ? rtrim(rtrim((string) $q, '0'), '.') : (string) $q;
    $date = fn ($d) => $d?->format($settings?->date_format ?: 'd M Y');
    $customer = $invoice->party_snapshot ?? [];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $challan ? 'Delivery challan' : 'Invoice' }} {{ $invoice->number }}</title>
<style>
    @page { margin: 18mm 16mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2328; }
    h1 { font-size: 15pt; margin: 0; }
    .muted { color: #5f6670; }
    .accent { color: {{ $accent }}; }
    .head td { vertical-align: top; }
    .title { text-align: right; }
    .title .doc { font-size: 17pt; font-weight: bold; letter-spacing: 1px; color: {{ $accent }}; text-transform: uppercase; }
    .band { margin: 6mm 0 4mm; border-top: 2px solid {{ $accent }}; }
    .meta td { padding: 1px 0; vertical-align: top; }
    .meta .k { color: #5f6670; width: 30mm; }
    table { border-collapse: collapse; width: 100%; }
    .lines th { background: {{ $accent }}; color: #fff; font-weight: bold; text-align: left; padding: 4px 5px; font-size: 8.5pt; }
    .lines td { padding: 4px 5px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
    .num, .lines th.num { text-align: right; white-space: nowrap; }
    .code { color: #5f6670; font-size: 8pt; }
    .totals { width: 70mm; margin-left: auto; margin-top: 3mm; }
    .totals td { padding: 2px 5px; }
    .totals .grand td { border-top: 1.5px solid #1f2328; font-weight: bold; font-size: 11pt; padding-top: 4px; }
    .words { margin-top: 4mm; font-style: italic; }
    .stamp { margin: 0 0 4mm; padding: 3px 8px; border: 1.5px solid #b42318; color: #b42318; font-weight: bold; text-align: center; letter-spacing: 1px; }
    .notes { margin-top: 5mm; }
    .sign { margin-top: 22mm; }
    .sign td { width: 50%; padding: 0 8mm 0 0; }
    .sign .line { border-top: 1px solid #1f2328; padding-top: 3px; width: 60mm; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; text-align: center; font-size: 7.5pt; color: #5f6670; }
</style>
</head>
<body>
@if ($invoice->status === 'draft')
    <div class="stamp">DRAFT — NOT POSTED</div>
@elseif ($invoice->status === 'cancelled')
    <div class="stamp">CANCELLED</div>
@endif

<table class="head">
    <tr>
        <td>
            <h1>{{ $company->trading_name ?: $company->legal_name }}</h1>
            @if ($company->trading_name && $company->trading_name !== $company->legal_name)<div class="muted">{{ $company->legal_name }}</div>@endif
            @if ($company->registered_address)<div>{{ $company->registered_address }}</div>@endif
            <div class="muted">{{ collect([$company->phone, $company->email, $company->website])->filter()->implode(' · ') }}</div>
            @if ($settings?->show_tax_no_on_invoice && $company->tax_registration_no)<div>BIN/TIN: {{ $company->tax_registration_no }}</div>@endif
        </td>
        <td class="title">
            <div class="doc">{{ $challan ? 'Delivery challan' : 'Invoice' }}</div>
            <div><strong>{{ $challan && $invoice->challan_no ? $invoice->challan_no : $invoice->number }}</strong></div>
            @if ($challan && $invoice->challan_no)<div class="muted">Invoice {{ $invoice->number }}</div>@endif
        </td>
    </tr>
</table>
<div class="band"></div>

<table class="head">
    <tr>
        <td style="width: 55%">
            <div class="muted">{{ $challan ? 'Deliver to' : 'Bill to' }}</div>
            <div><strong>{{ $customer['name'] ?? $invoice->party?->name }}</strong> <span class="code">{{ $customer['code'] ?? '' }}</span></div>
            @if (! empty($customer['address']))<div>{{ $customer['address'] }}</div>@endif
            @if (! empty($customer['mobile']))<div>{{ $customer['mobile'] }}</div>@endif
        </td>
        <td>
            <table class="meta">
                <tr><td class="k">Invoice date</td><td>{{ $date($invoice->invoice_date) }}</td></tr>
                @unless ($challan)<tr><td class="k">Due date</td><td>{{ $date($invoice->due_date) }}</td></tr>@endunless
                @if ($invoice->customer_po_ref)<tr><td class="k">Your PO</td><td>{{ $invoice->customer_po_ref }}</td></tr>@endif
                <tr><td class="k">From</td><td>{{ $invoice->warehouse?->name }}</td></tr>
                @if ($challan && $invoice->vehicle_no)<tr><td class="k">Vehicle</td><td>{{ $invoice->vehicle_no }}</td></tr>@endif
                @if ($challan && $invoice->delivered_at)<tr><td class="k">Delivered</td><td>{{ $invoice->delivered_at->setTimezone('Asia/Dhaka')->format('d M Y, H:i') }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table class="lines" style="margin-top: 5mm">
    <thead>
        <tr>
            <th style="width: 6mm">#</th>
            <th>Item</th>
            <th class="num">Qty</th>
            @unless ($challan)
                <th class="num">Price (Tk)</th>
                <th class="num">Disc</th>
                <th class="num">Amount (Tk)</th>
            @endunless
        </tr>
    </thead>
    <tbody>
    @foreach ($invoice->lines as $l)
        <tr>
            <td>{{ $l->line_no }}</td>
            <td>{{ $l->product_name }} <span class="code">{{ $l->product_code }}</span>@if ($l->description)<br><span class="muted">{{ $l->description }}</span>@endif</td>
            <td class="num">{{ $qty($l->quantity) }} {{ $l->unit_name }}</td>
            @unless ($challan)
                <td class="num">{{ $money($l->unit_price) }}</td>
                <td class="num">{{ (float) $l->discount_pct > 0 ? $qty($l->discount_pct).' %' : '' }}</td>
                <td class="num">{{ $money($l->line_total) }}</td>
            @endunless
        </tr>
    @endforeach
    </tbody>
</table>

@unless ($challan)
    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
        @if ($invoice->discount_total->isPositive())<tr><td>Discount</td><td class="num">−{{ $money($invoice->discount_total) }}</td></tr>@endif
        @if ($invoice->tax_total->isPositive())<tr><td>Tax</td><td class="num">{{ $money($invoice->tax_total) }}</td></tr>@endif
        @unless ($invoice->rounding_adjustment->isZero())<tr><td>Rounding</td><td class="num">{{ $invoice->rounding_adjustment->isNegative() ? '−' : '' }}{{ $money($invoice->rounding_adjustment->abs()) }}</td></tr>@endunless
        <tr class="grand"><td>Total (Tk)</td><td class="num">{{ $money($invoice->total) }}</td></tr>
    </table>
    @if ($settings?->invoice_show_words ?? true)
        <div class="words">{{ \App\Domain\Shared\AmountInWords::of($invoice->total, 'BDT') }}</div>
    @endif
@endunless

@if (! $challan && $invoice->notes)
    <div class="notes"><span class="muted">Notes:</span> {{ $invoice->notes }}</div>
@endif

@if ($settings?->invoice_show_signature ?? true)
    <table class="sign">
        <tr>
            <td><div class="line">{{ $challan ? 'Received by (signature, date)' : 'Customer signature' }}</div></td>
            <td><div class="line">For {{ $company->trading_name ?: $company->legal_name }}</div></td>
        </tr>
    </table>
@endif

<div class="footer">{{ $settings?->invoice_footer_note ?: 'Thank you for your business.' }}</div>
</body>
</html>
