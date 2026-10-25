Dear {{ $customerName }},

Please find attached invoice {{ $number }} for {{ $total }}, due on {{ $dueDate }}.
@if ($note)

{{ $note }}
@endif

Thank you for your business.

{{ $companyName }}
