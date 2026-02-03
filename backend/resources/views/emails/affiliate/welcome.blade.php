@component('mail::message')
# {{ __('Welcome to the :term Program!', ['term' => $affiliateTerm]) }}

{{ __('Hi :name,', ['name' => $affiliateName]) }}

{{ __("You've been added as a :term for **:event**.", ['term' => strtolower($affiliateTerm), 'event' => $eventTitle]) }}

**{{ __('Your Link:') }}**
{{ $affiliateUrl }}

{{ __('Share this link and click the button below to view your stats.') }}

@component('mail::button', ['url' => $loginUrl])
{{ __('Access :term Portal', ['term' => $affiliateTerm]) }}
@endcomponent

{{ __('Thanks for being part of our :term program!', ['term' => strtolower($affiliateTerm)]) }}

@endcomponent
