@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \Illuminate\Support\Collection $attendees */ @endphp
@php /** @var array<int,string> $qrPngs */ @endphp
@php /** @var array<int,string> $qrFilenames */ @endphp
@php /** @var array<int,string> $attendeeTicketUrls */ @endphp
@php /** @var array<int,string> $googleWalletUrls */ @endphp
@php /** @var bool $hasOtherAttendees */ @endphp
@php /** @var bool $isReminder */ @endphp
@php /** @see \HiEvents\Mail\Order\OrderTicketsMail */ @endphp

<x-mail::message>
@if(!empty($isReminder))
# {{ __('Your event is coming up!') }} 🎟️
@else
# {{ __('You\'re going to') }} {{ $event->getTitle() }}! 🎉
@endif

@if(!empty($isReminder))
{{ __('Your event is coming up! This email holds the tickets for everyone in your order, including your own — save it, the QR codes scan offline once opened. A printable PDF is attached as backup.') }}
@else
{{ __('Here are the tickets for everyone in your order, including your own. Save this email or the attached PDF for easy access on the day.') }}
@endif

@if(!empty($hasOtherAttendees))
{{ __('Each of the other attendees has also been emailed a copy of their own individual ticket.') }}
@endif

@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 4px; background-color: #f8d7da; color: #842029; margin: 1.5rem 0; padding: 1rem;">
<p>
{{ __('ℹ️ Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

@foreach($attendees as $attendee)
<hr>

## {{ trim($attendee->getFirstName() . ' ' . $attendee->getLastName()) }}

**{{ $attendee->getProduct()?->getTitle() ?? __('Ticket') }}**

@if(!empty($qrPngs[$attendee->getId()]))
<div style="text-align: center; margin: 1.5rem 0;">
<img src="{{ $message->embedData($qrPngs[$attendee->getId()], $qrFilenames[$attendee->getId()], 'image/png') }}" alt="{{ __('QR code for :name', ['name' => $attendee->getFirstName()]) }}" width="220" height="220" style="display: inline-block; max-width: 100%; height: auto;">
</div>
@endif

@php $googleWalletUrlForAttendee = $googleWalletUrls[$attendee->getId()] ?? null; @endphp
@php $frontendUrl = rtrim(config('app.frontend_url') ?? '', '/'); @endphp
@if($eventSettings->getWalletPassesEnabled() && (config('wallet.apple.pass_type_id') || !empty($googleWalletUrlForAttendee)))
<div style="text-align: center; margin: 0.5rem 0 1rem 0;">
@if(config('wallet.apple.pass_type_id'))
<a href="{{ url('/public/attendee/' . $event->getId() . '/' . $attendee->getShortId() . '/apple-pass.pkpass') }}" style="text-decoration: none; display: inline-block; margin: 0 6px;">
<img src="{{ $frontendUrl }}/wallet/add-to-apple-wallet.png" alt="{{ __('Add to Apple Wallet') }}" height="44" style="height: 44px; width: auto; border: 0;">
</a>
@endif
@if(!empty($googleWalletUrlForAttendee))
<a href="{{ $googleWalletUrlForAttendee }}" style="text-decoration: none; display: inline-block; margin: 0 6px;">
<img src="{{ $frontendUrl }}/wallet/save-to-google-wallet.png" alt="{{ __('Save to Google Wallet') }}" height="44" style="height: 44px; width: auto; border: 0;">
</a>
@endif
</div>
@endif

<x-mail::button :url="$attendeeTicketUrls[$attendee->getId()]">
{{ __('View Ticket') }}
</x-mail::button>

@endforeach

<hr>

<p style="text-align: center; font-size: 0.9em; color: #555;">
{{ __('A PDF with all tickets is attached as a backup — open this email at the gate, your QR codes work offline.') }}
</p>

{{ __('If you have any questions or need assistance, please reply to this email or contact the event organizer') }}
{{ __('at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
