Dear {!! $greetingName !!},

@foreach ($lines as $line)
{!! $line !!}
@endforeach
@if ($link)

Open in Pluto: {!! $link !!}
@endif

{!! $companyName !!} · Pluto
You can change which e-mails you get under Settings › Notifications.
