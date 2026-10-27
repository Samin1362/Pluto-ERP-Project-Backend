@php
    /** @var array $r ReportData; $rows from ReportExporter::tableRows */
    $numeric = array_map(fn ($c) => in_array($c['type'], ['amount', 'qty', 'pct'], true), $r['columns']);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $r['title'] }}</title>
<style>
    @page { margin: 14mm 12mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1f2328; }
    h1 { font-size: 13pt; margin: 0; }
    .muted { color: #5f6670; }
    .title { text-align: right; }
    .title .doc { font-size: 13pt; font-weight: bold; color: #0b6b5e; }
    .band { margin: 4mm 0 3mm; border-top: 2px solid #0b6b5e; }
    table { border-collapse: collapse; width: 100%; }
    .lines th { background: #0b6b5e; color: #fff; text-align: left; padding: 4px 5px; font-size: 8pt; }
    .lines td { padding: 2.5px 5px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
    .num, .lines th.num { text-align: right; white-space: nowrap; }
    .strong td { font-weight: bold; }
    .checks { margin-top: 4mm; }
    .footer { position: fixed; bottom: -9mm; left: 0; right: 0; text-align: center; font-size: 7pt; color: #5f6670; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>{{ $company->trading_name ?: $company->legal_name }}</h1></td>
        <td class="title"><div class="doc">{{ $r['title'] }}</div><div class="muted">{{ $period }}</div>
            @if (! empty($r['basis']))<div class="muted">{{ $r['basis'] }}</div>@endif</td>
    </tr>
</table>
<div class="band"></div>
<table class="lines">
    <thead><tr>@foreach ($r['columns'] as $i => $c)<th class="{{ $numeric[$i] ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($rows as [$cells, $strong])
        <tr class="{{ $strong ? 'strong' : '' }}">
            @foreach ($cells as $i => $v)
                <td class="{{ $numeric[$i] ? 'num' : '' }}">@if ($numeric[$i]){{ $money($v) }}@else{!! str_replace('  ', '&nbsp;&nbsp;&nbsp;', e((string) $v)) !!}@endif</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
@if (! empty($r['checks']))
    <div class="checks">
        @foreach ($r['checks'] as $c)<div>{{ $c['ok'] ? '✓' : '!' }} {{ $c['message'] }}</div>@endforeach
    </div>
@endif
<div class="footer">Reads posted vouchers only · printed {{ now('Asia/Dhaka')->format('j M Y, g:i a') }}</div>
</body>
</html>
