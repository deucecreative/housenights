@php /** @var string $affiliateName */ @endphp
@php /** @var string $eventTitle */ @endphp
@php /** @var string $affiliateTerm */ @endphp
@php /** @var string $portalUrl */ @endphp

<x-mail::message>
# {{ __('Your :term Dashboard Access', ['term' => $affiliateTerm]) }}

{{ __('Hello :name,', ['name' => $affiliateName]) }}

{{ __('Click the button below to access your :term dashboard for :event.', ['term' => strtolower($affiliateTerm), 'event' => $eventTitle]) }}

<x-mail::button :url="$portalUrl">
{{ __('Access :term Dashboard', ['term' => $affiliateTerm]) }}
</x-mail::button>

{{ __('If you did not request this, please ignore this email.') }}

{{ __('Thank you') }}
</x-mail::message>
