<?php

namespace HiEvents\Services\Domain\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\StringHelper;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * Builds an iCalendar (.ics) string for an event, shared by the ticket emails
 * so the calendar invite is generated consistently in one place.
 */
class GenerateEventIcsService
{
    /**
     * @param string $uniqueId Stable unique identifier for the calendar event
     *                         (e.g. per-attendee or per-order) so calendar
     *                         clients can de-duplicate / update the entry.
     */
    public function generate(
        EventDomainObject        $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject    $organizer,
        string                   $uniqueId,
    ): string
    {
        $startDateTime = Carbon::parse($event->getStartDate(), $event->getTimezone());
        $endDateTime = $event->getEndDate() ? Carbon::parse($event->getEndDate(), $event->getTimezone()) : null;

        $calendarEvent = Event::create()
            ->name($event->getTitle())
            ->uniqueIdentifier($uniqueId)
            ->startsAt($startDateTime)
            ->url($event->getEventUrl())
            ->organizer($organizer->getEmail(), $organizer->getName());

        if ($event->getDescription()) {
            $calendarEvent->description(StringHelper::previewFromHtml($event->getDescription()));
        }

        if ($eventSettings->getLocationDetails()) {
            $calendarEvent->address($eventSettings->getAddressString());
        }

        if ($endDateTime) {
            $calendarEvent->endsAt($endDateTime);
        }

        return Calendar::create()
            ->event($calendarEvent)
            ->get();
    }
}
