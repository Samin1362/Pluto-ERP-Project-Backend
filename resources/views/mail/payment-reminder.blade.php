Dear {{ $partyName }},

This is a friendly reminder that the following invoices are past their due date:

@foreach ($invoices as $i)
- {{ $i['number'] }} of {{ \Carbon\CarbonImmutable::parse($i['invoice_date'])->format('j M Y') }}, due {{ \Carbon\CarbonImmutable::parse($i['due_date'])->format('j M Y') }} ({{ $i['days_late'] }} {{ $i['days_late'] === 1 ? 'day' : 'days' }} late): Tk {{ number_format((float) $i['balance_due'], 2) }} still due
@endforeach

Total overdue: Tk {{ number_format((float) $totalDue, 2) }}

Your statement of account is attached. If you have already paid, thank you, and please ignore this message. If anything does not match your records, please reply to this e-mail.

{{ $companyName }}
