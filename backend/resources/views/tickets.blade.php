@php use Carbon\Carbon; use HiEvents\Helper\DateHelper; @endphp
@php /** @var \Illuminate\Support\Collection|array $attendees */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject|null $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject|null $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject|null $order */ @endphp
@php /** @var array<int, string> $qrCodes */ @endphp

@php
    $order = $order ?? null;
    $attendeeList = is_array($attendees) ? $attendees : $attendees->all();
    $attendeeCount = count($attendeeList);

    $venueLines = [];
    if ($eventSettings) {
        if ($eventSettings->getIsOnlineEvent()) {
            $venueLines = [__('Online Event')];
        } else {
            $location = $eventSettings->getLocationDetails() ?? [];
            if (!empty($location['venue_name'])) {
                $venueLines[] = $location['venue_name'];
            }
            $street = trim(($location['address_line_1'] ?? '') . ' ' . ($location['address_line_2'] ?? ''));
            if ($street !== '') {
                $venueLines[] = $street;
            }
            $cityPostcode = trim(($location['city'] ?? '') . ' ' . ($location['zip_or_postal_code'] ?? ''));
            if ($cityPostcode !== '') {
                $venueLines[] = $cityPostcode;
            }
            if (!empty($location['country'])) {
                $venueLines[] = $location['country'];
            }
            // Fallback to single-line address if nothing matched.
            if (empty($venueLines)) {
                $single = $eventSettings->getAddressString();
                if ($single !== '') {
                    $venueLines[] = $single;
                }
            }
        }
    }

    $formatEventDate = static function (?string $utc, ?string $timezone): ?string {
        if (!$utc) {
            return null;
        }
        try {
            return Carbon::parse(DateHelper::convertFromUTC($utc, $timezone ?? 'UTC'))
                ->format('l, jS F Y · g:i A');
        } catch (\Throwable) {
            return null;
        }
    };
    $startDateText = $formatEventDate($event->getStartDate(), $event->getTimezone());
    $endDateText = $formatEventDate($event->getEndDate(), $event->getTimezone());

    $organizerLogo = null;
    if ($organizer && method_exists($organizer, 'getImages') && $organizer->getImages()) {
        $logo = $organizer->getImages()->first(
            fn($i) => $i->getType() === \HiEvents\DomainObjects\Enums\ImageType::ORGANIZER_LOGO->name
        );
        if ($logo) {
            $organizerLogo = \HiEvents\Helper\Url::getCdnUrl($logo->getPath());
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $event->getTitle() }} — {{ __('Tickets') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #1a1a1a;
            padding: 30px 35px;
        }

        .ticket-page {
            width: 100%;
        }

        .ticket-page.has-break {
            page-break-after: always;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table.header-table td {
            vertical-align: top;
            padding: 0;
        }

        .event-title {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.5px;
            margin: 0 0 6px 0;
        }

        .event-meta {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 12px;
            color: #555;
            line-height: 1.6;
        }

        .organizer-block {
            text-align: right;
            color: #555;
            line-height: 1.4;
        }

        .organizer-name {
            font-weight: bold;
            color: #1a1a1a;
            font-size: 13px;
        }

        .organizer-logo {
            max-height: 50px;
            max-width: 160px;
            margin-bottom: 6px;
        }

        .divider {
            border-top: 1px solid #e6e6e6;
            margin: 18px 0;
        }

        .attendee-info {
            background: #f9f9fb;
            padding: 16px 18px;
            border-radius: 4px;
            margin-bottom: 18px;
        }

        .attendee-info td {
            padding: 4px 0;
            vertical-align: top;
        }

        .info-label {
            color: #888;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 3px;
        }

        .info-value {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: #1a1a1a;
        }

        .info-sub {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 11px;
            color: #888;
            display: block;
            margin-top: 2px;
        }

        .order-ref {
            font-family: 'outfit', Arial, sans-serif;
            font-size: 11px;
            color: #888;
            margin-top: 4px;
            white-space: nowrap;
        }

        .ticket-footer-text {
            display: block;
            max-width: 480px;
            margin: 0 auto;
        }

        .qr-section {
            margin-top: 24px;
            text-align: center;
        }

        .qr-section img {
            width: 250px;
            height: 250px;
        }

        .short-id {
            margin-top: 12px;
            font-family: 'outfit', Arial, sans-serif;
            font-size: 13px;
            letter-spacing: 1.5px;
            color: #555;
            text-transform: uppercase;
        }

        .ticket-footer {
            margin-top: 30px;
            padding-top: 14px;
            border-top: 1px solid #e6e6e6;
            text-align: center;
            font-size: 10px;
            color: #888;
        }
    </style>
</head>
<body>

@foreach($attendeeList as $index => $attendee)
    @php
        $isLast = $index === $attendeeCount - 1;
        $product = $attendee->getProduct();
        $productTitle = $product ? $product->getTitle() : '';
        $attendeeName = trim($attendee->getFirstName() . ' ' . $attendee->getLastName());
        $attendeeEmail = method_exists($attendee, 'getEmail') ? $attendee->getEmail() : null;
        $attendeeId = $attendee->getId();
        $qr = $qrCodes[$attendeeId] ?? null;
    @endphp
    <div class="ticket-page {{ $isLast ? '' : 'has-break' }}">
        <table class="header-table">
            <tr>
                <td style="width: 55%;">
                    <h1 class="event-title">{{ $event->getTitle() }}</h1>
                    <div class="event-meta">
                        @if($startDateText)
                            <div>{{ $startDateText }}</div>
                        @endif
                        @if($endDateText && $endDateText !== $startDateText)
                            <div>{{ __('Ends') }} {{ $endDateText }}</div>
                        @endif
                        @foreach($venueLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </div>
                </td>
                <td class="organizer-block" style="width: 45%;">
                    @if($organizerLogo)
                        <div><img class="organizer-logo" src="{{ $organizerLogo }}" alt=""></div>
                    @endif
                    @if($organizer)
                        <div class="organizer-name">{{ $organizer->getName() }}</div>
                    @endif
                    @if($order)
                        <div class="order-ref">#{{ $order->getPublicId() }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="divider"></div>

        <table class="attendee-info">
            <tr>
                <td style="width: 50%;">
                    <span class="info-label">{{ __('Attendee') }}</span>
                    <span class="info-value">{{ $attendeeName !== '' ? $attendeeName : '—' }}</span>
                    @if($attendeeEmail)
                        <span class="info-sub">{{ $attendeeEmail }}</span>
                    @endif
                </td>
                <td style="width: 50%;">
                    <span class="info-label">{{ __('Ticket') }}</span>
                    <span class="info-value">{{ $productTitle !== '' ? $productTitle : '—' }}</span>
                    <span class="info-sub">{{ __('Ticket ID') }} {{ $attendee->getPublicId() }}</span>
                </td>
            </tr>
        </table>

        <div class="qr-section">
            @if($qr)
                <img src="data:image/png;base64,{{ $qr }}" alt="">
            @endif
            <div class="short-id">{{ $attendee->getShortId() }}</div>
        </div>

        <div class="ticket-footer">
            <span class="ticket-footer-text">{{ __('Please present this ticket at the door. Each QR code is unique to one attendee.') }}</span>
        </div>
    </div>
@endforeach

</body>
</html>
