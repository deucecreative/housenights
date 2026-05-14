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
@php /** @var string|null $googleWalletUrl */ @endphp
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

@php $frontendUrl = rtrim(config('app.frontend_url') ?? '', '/'); @endphp
@if($eventSettings->getWalletPassesEnabled() && (config('wallet.apple.pass_type_id') || !empty($googleWalletUrl)))
<div style="text-align: center; margin: 1rem 0 1.5rem 0;">
@if(config('wallet.apple.pass_type_id'))
<a href="{{ url('/public/attendee/' . $event->getId() . '/' . $attendee->getShortId() . '/apple-pass') }}" style="text-decoration: none; display: inline-block; margin: 0 6px;">
<img src="{{ $frontendUrl }}/wallet/add-to-apple-wallet.png" alt="{{ __('Add to Apple Wallet') }}" height="48" style="height: 48px; width: auto; border: 0;">
</a>
@endif
@if(!empty($googleWalletUrl))
<a href="{{ $googleWalletUrl }}" style="text-decoration: none; display: inline-block; margin: 0 6px;">
<img src="{{ $frontendUrl }}/wallet/save-to-google-wallet.png" alt="{{ __('Save to Google Wallet') }}" height="48" style="height: 48px; width: auto; border: 0;">
</a>
@endif
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
