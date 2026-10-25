@php
    /** @var array $v VoucherResource of a receipt */
    $p = $v['payment'];
    $money = fn ($m) => \App\Domain\Shared\Taka::format(\App\Domain\Shared\Money::of((string) $m), symbol: false);
    $methods = ['cash' => 'Cash', 'cheque' => 'Cheque', 'bank_transfer' => 'Bank transfer', 'beftn' => 'BEFTN', 'rtgs' => 'RTGS', 'npsb' => 'NPSB', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'card' => 'Card'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Money receipt {{ $v['voucher_number'] }}</title>
<style>
    @page { margin: 12mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2328; }
    h1 { font-size: 13pt; margin: 0; }
    .muted { color: #5f6670; }
    .title { text-align: right; }
    .title .doc { font-size: 14pt; font-weight: bold; color: #0b6b5e; text-transform: uppercase; letter-spacing: 1px; }
    .band { margin: 4mm 0; border-top: 2px solid #0b6b5e; }
    table { border-collapse: collapse; width: 100%; }
    .rows td { padding: 3px 0; vertical-align: top; }
    .rows .k { color: #5f6670; width: 40mm; }
    .amount { font-size: 15pt; font-weight: bold; }
    .lines th { text-align: left; border-bottom: 1px solid #1f2328; padding: 3px 4px; font-size: 8.5pt; }
    .lines td { padding: 3px 4px; border-bottom: 1px solid #e3e6ea; }
    .num { text-align: right; white-space: nowrap; }
    .stamp { margin: 0 0 3mm; padding: 2px 8px; border: 1.5px solid #b42318; color: #b42318; font-weight: bold; text-align: center; }
    .sign { margin-top: 14mm; }
    .sign td { width: 50%; }
    .sign .line { border-top: 1px solid #1f2328; padding-top: 3px; width: 55mm; }
</style>
</head>
<body>
@if ($v['status'] === 'reversed')<div class="stamp">CANCELLED</div>@endif
<table>
    <tr>
        <td><h1>{{ $company->trading_name ?: $company->legal_name }}</h1>
            @if ($company->registered_address)<div class="muted">{{ $company->registered_address }}</div>@endif
            <div class="muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div></td>
        <td class="title"><div class="doc">Money receipt</div><div><strong>{{ $v['voucher_number'] }}</strong></div>
            <div class="muted">{{ \Carbon\CarbonImmutable::parse($v['entry_date'])->format('j M Y') }}</div></td>
    </tr>
</table>
<div class="band"></div>
<table class="rows">
    <tr><td class="k">Received from</td><td><strong>{{ $p['party']['name'] ?? '' }}</strong> <span class="muted">{{ $p['party']['code'] ?? '' }}</span></td></tr>
    <tr><td class="k">Amount</td><td class="amount">Tk {{ $money($p['amount']) }}</td></tr>
    <tr><td class="k">In words</td><td><em>{{ $v['amount_in_words'] }}</em></td></tr>
    <tr><td class="k">Paid by</td><td>{{ $methods[$p['method']] ?? $p['method'] }}@if ($p['cheque_number']) · cheque {{ $p['cheque_number'] }}, {{ $p['drawn_on'] }}, dated {{ \Carbon\CarbonImmutable::parse($p['cheque_date'])->format('j M Y') }}@endif @if ($p['transaction_reference']) · ref. {{ $p['transaction_reference'] }}@endif</td></tr>
    <tr><td class="k">Into</td><td>{{ $p['money_account']['name'] }}</td></tr>
    @if ($v['narration'])<tr><td class="k">Note</td><td>{{ $v['narration'] }}</td></tr>@endif
</table>
@if (count($p['allocations']))
    <table class="lines" style="margin-top: 4mm">
        <thead><tr><th>Against</th><th class="num">Amount (Tk)</th></tr></thead>
        <tbody>
        @foreach ($p['allocations'] as $a)<tr><td>{{ $a['document_number'] }}</td><td class="num">{{ $money($a['amount']) }}</td></tr>@endforeach
        @if (\App\Domain\Shared\Money::of($p['unapplied_amount'])->isPositive())<tr><td>Advance (not yet applied)</td><td class="num">{{ $money($p['unapplied_amount']) }}</td></tr>@endif
        </tbody>
    </table>
@endif
<table class="sign"><tr><td><div class="line">Received by</div></td><td><div class="line">For {{ $company->trading_name ?: $company->legal_name }}</div></td></tr></table>
</body>
</html>
