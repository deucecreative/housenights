<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Attendee\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Attendee\Public\DownloadAttendeeTicketPublicHandler;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadAttendeeTicketPublicAction extends BaseAction
{
    public function __construct(
        private readonly DownloadAttendeeTicketPublicHandler $handler,
    ) {
    }

    public function __invoke(int $eventId, string $attendeeShortId): LaravelResponse
    {
        try {
            $pdf = $this->handler->handle(
                eventId: $eventId,
                attendeeShortId: $attendeeShortId,
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return new LaravelResponse($pdf->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $pdf->filename),
            'Content-Length' => (string)strlen($pdf->bytes),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
