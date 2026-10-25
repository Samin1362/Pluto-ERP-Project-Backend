Dear {{ $partyName }},

Please find attached your statement of account for {{ \Carbon\CarbonImmutable::parse($periodFrom)->format('j M Y') }} to {{ \Carbon\CarbonImmutable::parse($periodTo)->format('j M Y') }}.

If anything does not match your records, please reply to this e-mail.

{{ $companyName }}
