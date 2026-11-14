@php
    // PEP-497: headings and rules in the company's brand colour (darkened if too light to read on white); without one they stay as before.
    $brand = \App\Domain\Company\BrandPalette::printColor($company->brand_color ?? null, '#1d2329');
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $lc['lc_number'] }}</title>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1d2329; }
h1 { font-size: 16px; margin: 0 0 4px; color: {{ $brand }}; } h2 { font-size: 12px; margin: 14px 0 6px; border-bottom: 1px solid {{ $brand }}; padding-bottom: 2px; }
table { width: 100%; border-collapse: collapse; } td, th { padding: 3px 4px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
th { color: #555; font-weight: normal; width: 30%; } .num { text-align: right; } .muted { color: #666; }
</style></head><body>
<div class="muted">{{ $company->trading_name ?: $company->legal_name }}</div>
<h1>Letter of credit {{ $lc['lc_number'] }}</h1>
<div class="muted">Status: {{ str_replace('_', ' ', $lc['status']) }} @if($lc['bank_reference']) · Bank reference {{ $lc['bank_reference'] }} @endif</div>
<h2>Terms</h2>
<table>
<tr><th>Beneficiary</th><td>{{ $lc['party']['name'] }}</td></tr>
<tr><th>Goods</th><td>{{ $lc['goods_description'] }}</td></tr>
<tr><th>Amount</th><td>{{ $lc['currency_code'] }} {{ number_format((float) $lc['amount'], 2) }} (tolerance {{ rtrim(rtrim($lc['tolerance_pct'], '0'), '.') }}%)</td></tr>
<tr><th>Tenor</th><td>{{ ucfirst($lc['tenor']) }}@if($lc['usance_days']), {{ $lc['usance_days'] }} days @endif · {{ $lc['incoterm'] }}</td></tr>
<tr><th>Issuing bank</th><td>{{ $lc['issuing_bank']['name'] ?? '—' }}</td></tr>
<tr><th>Opened / expiry</th><td>{{ $lc['opened_on'] ?? '—' }} / {{ $lc['expiry_date'] ?? '—' }}</td></tr>
<tr><th>Paid / outstanding</th><td>{{ $lc['currency_code'] }} {{ number_format((float) $lc['paid_foreign'], 2) }} / {{ number_format((float) $lc['outstanding_foreign'], 2) }}</td></tr>
</table>
@if(!empty($lc['lines']))
<h2>Items</h2>
<table>
<tr><th style="width:4%">#</th><th style="width:36%">Item</th><th class="num" style="width:15%">Quantity</th><th style="width:8%">Unit</th><th class="num" style="width:12%">Unit cost</th><th class="num" style="width:12%">Received</th><th class="num" style="width:13%">Total ({{ $lc['currency_code'] }})</th></tr>
@foreach($lc['lines'] as $l)
<tr><td>{{ $l['line_no'] }}</td><td>{{ $l['product_name'] }} <span class="muted">{{ $l['product_code'] }}</span>@if($l['description'])<br><span class="muted">{{ $l['description'] }}</span>@endif</td>
<td class="num">{{ $l['quantity'] }}</td><td>{{ $l['unit'] }}</td><td class="num">{{ $l['unit_cost'] }}</td><td class="num">{{ $l['received_quantity'] }}</td><td class="num">{{ $l['line_total'] }}</td></tr>
@endforeach
<tr><th colspan="6" class="num">Items total</th><td class="num"><strong>{{ $lc['lines_total'] }}</strong></td></tr>
</table>
@endif
<h2>Documents</h2>
<table>@foreach($lc['documents'] as $d)<tr><td>{{ $d['name'] }}</td><td>{{ $d['provided_by'] }}</td><td>{{ str_replace('_', ' ', $d['status']) }}</td></tr>@endforeach</table>
<h2>Shipments</h2>
<table>@foreach($lc['shipments'] as $s)<tr><td>Lot {{ $s['lot_no'] }}</td><td>{{ str_replace('_', ' ', $s['status']) }}</td><td>{{ $s['bl_number'] ?? '' }}</td><td>{{ $s['eta'] ?? '' }}</td></tr>@endforeach</table>
<h2>Charges</h2>
<table>@foreach($lc['charges'] as $c)<tr><td>{{ $c['charge_date'] }}</td><td>{{ ucfirst(str_replace('_', ' ', $c['charge_type'])) }}</td><td>{{ $c['status'] }}</td><td class="num">{{ number_format((float) $c['amount'], 2) }}</td></tr>@endforeach</table>
<h2>Payments</h2>
<table>@foreach($lc['payments'] as $p)<tr><td>{{ $p['payment_date'] }}</td><td>{{ str_replace('_', ' ', $p['payment_kind']) }}</td><td class="num">{{ $lc['currency_code'] }} {{ number_format((float) $p['amount_foreign'], 2) }} @ {{ $p['bank_rate'] }}</td><td class="num">৳ {{ number_format((float) $p['amount_bdt'], 2) }}</td></tr>@endforeach</table>
</body></html>
