@php use HiEvents\Helper\DateHelper; @endphp
@php /** @uses \HiEvents\Mail\Order\OrderSummary */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\AttendeeDomainObject $attendee */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp

@php /** @var string $ticketUrl */ @endphp
@php /** @var bool $isReminder */ @endphp
@php /** @var string $qrPng */ @endphp
@php /** @var string $qrFilename */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketMail */ @endphp

<x-mail::message>
@if(!empty($isReminder))
# {{ __('Your event is coming up!') }} 🎟️
@else
# {{ __('You\'re going to') }} {{ $event->getTitle() }}! 🎉
@endif
<br>
<br>
@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 4px; background-color: #f8d7da; color: #842029; margin-bottom: 1.5rem; padding: 1rem;">
<p>
{{ __('ℹ️ Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

@if(!empty($qrPng))
<div style="text-align: center; margin: 1.5rem 0;">
<img src="{{ $message->embedData($qrPng, $qrFilename, 'image/png') }}" alt="{{ __('Your ticket QR code') }}" width="280" height="280" style="display: inline-block; max-width: 100%; height: auto;">
</div>
@endif

@if(!empty($isReminder))
{{ __('Your event is coming up! Save this email — your QR works offline once opened. The PDF is attached as a backup.') }}
@else
{{ __('Please find your ticket details below.') }}
@endif

<p style="text-align: center; font-size: 0.9em; color: #555;">
{{ __('PDF attached as backup — open this email at the gate, your QR works offline.') }}
</p>

<x-mail::button :url="$ticketUrl">
{{ __('View Ticket') }}
</x-mail::button>

{{ __('If you have any questions or need assistance, please reply to this email or contact the event organizer') }}
{{ __('at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
