{{-- Plain-text part. Unescaped on purpose: HTML entities would show literally here. --}}
{!! $heading !!}

Hi {!! $name !!},

{!! $intro !!}
@if (!empty($code))

    {!! $code !!}
@endif

@foreach ($lines as $line)
{!! $line !!}

@endforeach
--
Sent by the Ka-Agapay system of your Rural Health Unit. Please do not reply to this email.
