@php
    /**
     * A4 trade document (quotation, purchase order, credit or debit note; backlog P10): App\Domain\Documents\TradeDocumentPdf
     * passes the title, the document (lines + totals as stored), the party block label and the meta rows.
     * @var \Illuminate\Database\Eloquent\Model $doc
     */
    $accent = $settings?->invoice_accent_color ?: '#0b6b5e';
    $money = fn ($m) => \App\Domain\Shared\Taka::format(\App\Domain\Shared\Money::of((string) $m), symbol: false);
    $qty = fn ($q) => str_contains((string) $q, '.') ? rtrim(rtrim((string) $q, '0'), '.') : (string) $q;
    $party = $doc->party_snapshot ?? ['name' => $doc->party?->name, 'code' => $doc->party?->code, 'address' => $doc->party?->address, 'mobile' => $doc->party?->mobile];
    $ccy = $currency ?? 'Tk';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $doc->number }}</title>
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
@if ($stamp)
    <div class="stamp">{{ $stamp }}</div>
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
            <div class="doc">{{ $title }}</div>
            <div><strong>{{ $doc->number }}</strong></div>
        </td>
    </tr>
</table>
<div class="band"></div>

<table class="head">
    <tr>
        <td style="width: 55%">
            <div class="muted">{{ $partyLabel }}</div>
            <div><strong>{{ $party['name'] ?? '' }}</strong> <span class="code">{{ $party['code'] ?? '' }}</span></div>
            @if (! empty($party['address']))<div>{{ $party['address'] }}</div>@endif
            @if (! empty($party['mobile']))<div>{{ $party['mobile'] }}</div>@endif
        </td>
        <td>
            <table class="meta">
                @foreach ($meta as $k => $v)
                    @if ($v !== null && $v !== '')<tr><td class="k">{{ $k }}</td><td>{{ $v }}</td></tr>@endif
                @endforeach
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
            <th class="num">{{ $priceLabel ?? 'Price' }} ({{ $ccy }})</th>
            <th class="num">Disc</th>
            <th class="num">Amount ({{ $ccy }})</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($lines as $l)
        <tr>
            <td>{{ $l['line_no'] }}</td>
            <td>{{ $l['name'] }} <span class="code">{{ $l['code'] }}</span>@if (! empty($l['description']))<br><span class="muted">{{ $l['description'] }}</span>@endif</td>
            <td class="num">{{ $qty($l['quantity']) }} {{ $l['unit'] }}</td>
            <td class="num">{{ $money($l['price']) }}</td>
            <td class="num">{{ (float) ($l['discount_pct'] ?? 0) > 0 ? $qty($l['discount_pct']).' %' : '' }}</td>
            <td class="num">{{ $money($l['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    @foreach ($totals as $k => $v)
        <tr @if ($loop->last) class="grand" @endif><td>{{ $k }}</td><td class="num">{{ $v }}</td></tr>
    @endforeach
</table>
@if ($words)
    <div class="words">{{ $words }}</div>
@endif

@if ($doc->notes)
    <div class="notes"><span class="muted">{{ $notesLabel ?? 'Notes' }}:</span> {{ $doc->notes }}</div>
@endif

@if ($settings?->invoice_show_signature ?? true)
    <table class="sign">
        <tr>
            <td><div class="line">{{ $signLeft ?? 'Received by' }}</div></td>
            <td><div class="line">For {{ $company->trading_name ?: $company->legal_name }}</div></td>
        </tr>
    </table>
@endif

<div class="footer">{{ $footer ?? ($settings?->invoice_footer_note ?: 'Thank you for your business.') }}</div>
</body>
</html>
