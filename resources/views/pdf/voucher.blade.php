@php
    /** @var array $v VoucherResource of any journal entry (H14) */
    $money = fn ($m) => \App\Domain\Shared\Taka::format(\App\Domain\Shared\Money::of((string) $m), symbol: false);
    $titles = ['journal' => 'Journal voucher', 'payment' => 'Payment voucher', 'receipt' => 'Receipt voucher', 'contra' => 'Contra voucher', 'reversal' => 'Reversal entry'];
    $methods = ['cash' => 'Cash', 'cheque' => 'Cheque', 'bank_transfer' => 'Bank transfer', 'beftn' => 'BEFTN', 'rtgs' => 'RTGS', 'npsb' => 'NPSB', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'card' => 'Card'];
    $stamp = ['draft' => 'DRAFT', 'submitted' => 'AWAITING APPROVAL', 'rejected' => 'REJECTED', 'reversed' => 'REVERSED'][$v['status']] ?? null;
    $p = $v['payment'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $titles[$v['entry_type']] ?? 'Voucher' }} {{ $v['voucher_number'] }}</title>
<style>
    @page { margin: 14mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2328; }
    h1 { font-size: 13pt; margin: 0; }
    .muted { color: #5f6670; }
    .title { text-align: right; }
    .title .doc { font-size: 14pt; font-weight: bold; color: #0b6b5e; text-transform: uppercase; letter-spacing: 1px; }
    .band { margin: 4mm 0; border-top: 2px solid #0b6b5e; }
    table { border-collapse: collapse; width: 100%; }
    .rows td { padding: 2px 0; vertical-align: top; }
    .rows .k { color: #5f6670; width: 38mm; }
    .lines { margin-top: 4mm; }
    .lines th { text-align: left; border-bottom: 1px solid #1f2328; padding: 4px; font-size: 8.5pt; }
    .lines td { padding: 4px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
    .lines tfoot td { border-top: 1px solid #1f2328; border-bottom: 0; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    .stamp { margin: 0 0 3mm; padding: 2px 8px; border: 1.5px solid #b42318; color: #b42318; font-weight: bold; text-align: center; }
    .sign { margin-top: 18mm; }
    .sign td { width: 33%; }
    .sign .line { border-top: 1px solid #1f2328; padding-top: 3px; width: 48mm; }
</style>
</head>
<body>
@if ($stamp)<div class="stamp">{{ $stamp }}</div>@endif
<table>
    <tr>
        <td><h1>{{ $company->trading_name ?: $company->legal_name }}</h1>
            @if ($company->registered_address)<div class="muted">{{ $company->registered_address }}</div>@endif
            <div class="muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div></td>
        <td class="title"><div class="doc">{{ $titles[$v['entry_type']] ?? str_replace('_', ' ', $v['entry_type']) }}</div><div><strong>{{ $v['voucher_number'] }}</strong></div>
            <div class="muted">{{ \Carbon\CarbonImmutable::parse($v['entry_date'])->format('j M Y') }}</div></td>
    </tr>
</table>
<div class="band"></div>
<table class="rows">
    @if ($p)
        <tr><td class="k">{{ $p['direction'] === 'in' ? 'Received into' : ($p['direction'] === 'transfer' ? 'Moved from' : 'Paid from') }}</td><td>{{ $p['money_account']['name'] }}</td></tr>
        @if ($p['party'])<tr><td class="k">{{ $p['direction'] === 'in' ? 'Received from' : 'Paid to' }}</td><td><strong>{{ $p['party']['name'] }}</strong> <span class="muted">{{ $p['party']['code'] }}</span></td></tr>@endif
        <tr><td class="k">By</td><td>{{ $methods[$p['method']] ?? $p['method'] }}@if ($p['cheque_number']) · cheque {{ $p['cheque_number'] }}@if ($p['cheque_date']), dated {{ \Carbon\CarbonImmutable::parse($p['cheque_date'])->format('j M Y') }}@endif @endif @if ($p['transaction_reference']) · ref. {{ $p['transaction_reference'] }}@endif</td></tr>
    @endif
    @if ($v['reference'])<tr><td class="k">Reference</td><td>{{ $v['reference'] }}</td></tr>@endif
    @if ($v['narration'])<tr><td class="k">Narration</td><td>{{ $v['narration'] }}</td></tr>@endif
</table>
<table class="lines">
    <thead><tr><th style="width: 22mm">Code</th><th>Ledger</th><th class="num" style="width: 32mm">Debit (Tk)</th><th class="num" style="width: 32mm">Credit (Tk)</th></tr></thead>
    <tbody>
    @foreach ($v['lines'] as $l)
        <tr><td>{{ $l['ledger_code'] }}</td><td>{{ $l['ledger_name'] }}@if ($l['line_narration'])<div class="muted">{{ $l['line_narration'] }}</div>@endif</td>
            <td class="num">{{ \App\Domain\Shared\Money::of((string) $l['debit'])->isZero() ? '' : $money($l['debit']) }}</td>
            <td class="num">{{ \App\Domain\Shared\Money::of((string) $l['credit'])->isZero() ? '' : $money($l['credit']) }}</td></tr>
    @endforeach
    </tbody>
    <tfoot><tr><td></td><td>Total</td><td class="num">{{ $money($v['total_debit']) }}</td><td class="num">{{ $money($v['total_credit']) }}</td></tr></tfoot>
</table>
<p><span class="muted">In words:</span> <em>{{ $v['amount_in_words'] }}</em></p>
<table class="sign"><tr>
    <td><div class="line">Prepared by{{ $v['created_by'] ? ': '.$v['created_by']['name'] : '' }}</div></td>
    <td><div class="line">Approved by{{ $approvedBy ? ': '.$approvedBy : '' }}</div></td>
    <td><div class="line">Posted by{{ $postedBy ? ': '.$postedBy : '' }}</div></td>
</tr></table>
</body>
</html>
