@php
    /** @var array $s PartyStatement */
    $money = fn ($m) => \App\Domain\Shared\Taka::format(\App\Domain\Shared\Money::of((string) $m), symbol: false);
    $d = fn ($x) => \Carbon\CarbonImmutable::parse($x)->format('j M Y');
    $labels = ['not_due' => 'Not due', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90_plus' => 'Over 90 days', 'total' => 'Total'];
    $owes = ['dr' => 'You owe us', 'cr' => 'We owe you']; // the party's ledger: debit = they owe us
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Statement {{ $s['statement_no'] }}</title>
<style>
    @page { margin: 16mm 14mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2328; }
    h1 { font-size: 14pt; margin: 0; }
    .muted { color: #5f6670; }
    .title { text-align: right; }
    .title .doc { font-size: 15pt; font-weight: bold; color: #0b6b5e; text-transform: uppercase; letter-spacing: 1px; }
    .band { margin: 5mm 0 4mm; border-top: 2px solid #0b6b5e; }
    table { border-collapse: collapse; width: 100%; }
    .lines th { background: #0b6b5e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8pt; }
    .lines td { padding: 3px 5px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
    .num, .lines th.num { text-align: right; white-space: nowrap; }
    .summary td { padding: 2px 5px; }
    .closing td { border-top: 1.5px solid #1f2328; font-weight: bold; }
    .aging { margin-top: 5mm; }
    .aging th, .aging td { border: 1px solid #c9ced4; padding: 3px 5px; text-align: right; font-size: 8pt; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: 7.5pt; color: #5f6670; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>{{ $company->trading_name ?: $company->legal_name }}</h1>
            @if ($company->registered_address)<div class="muted">{{ $company->registered_address }}</div>@endif
            <div class="muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div></td>
        <td class="title"><div class="doc">Statement of account</div><div><strong>{{ $s['statement_no'] }}</strong></div>
            <div class="muted">{{ $d($s['period']['from']) }} – {{ $d($s['period']['to']) }}</div></td>
    </tr>
</table>
<div class="band"></div>
<div><strong>{{ $s['party']['name'] }}</strong> <span class="muted">{{ $s['party']['code'] }}</span></div>
@if ($party->address)<div>{{ $party->address }}</div>@endif

<table class="lines" style="margin-top: 4mm">
    <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th class="num">Debit (Tk)</th><th class="num">Credit (Tk)</th><th class="num">Balance (Tk)</th></tr></thead>
    <tbody>
        <tr><td>{{ $d($s['period']['from']) }}</td><td></td><td><em>Opening balance</em></td><td></td><td></td>
            <td class="num">{{ $money($s['summary']['opening']) }} {{ ucfirst($s['summary']['opening_side']) }}</td></tr>
        @foreach ($s['lines'] as $l)
            <tr><td>{{ $d($l['date']) }}</td><td>{{ $l['voucher_number'] }}</td><td>{{ $l['particulars'] }}@if ($l['narration'])<br><span class="muted">{{ $l['narration'] }}</span>@endif</td>
                <td class="num">{{ $l['debit'] !== '0.00' ? $money($l['debit']) : '' }}</td><td class="num">{{ $l['credit'] !== '0.00' ? $money($l['credit']) : '' }}</td>
                <td class="num">{{ $money($l['balance']) }} {{ ucfirst($l['balance_side']) }}</td></tr>
        @endforeach
    </tbody>
</table>
<table class="summary" style="width: 80mm; margin-left: auto; margin-top: 3mm">
    <tr><td>Total debits</td><td class="num">{{ $money($s['summary']['total_debit']) }}</td></tr>
    <tr><td>Total credits</td><td class="num">{{ $money($s['summary']['total_credit']) }}</td></tr>
    <tr class="closing"><td>{{ $owes[$s['summary']['closing_side']] }}</td><td class="num">Tk {{ $money($s['summary']['closing']) }}</td></tr>
</table>
@if ($s['aging'])
    <table class="aging">
        <tr>@foreach ($s['aging'] as $b)<th>{{ $labels[$b['key']] }}</th>@endforeach</tr>
        <tr>@foreach ($s['aging'] as $b)<td>{{ $money($b['amount']) }}</td>@endforeach</tr>
    </table>
@endif
<div class="footer">{{ $s['footer'] ?: 'Please let us know within 15 days if anything does not match your records.' }}</div>
</body>
</html>
