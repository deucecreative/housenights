<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Public\DownloadOrderTicketsPublicHandler;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadOrderTicketsPublicAction extends BaseAction
{
    public function __construct(
        private readonly DownloadOrderTicketsPublicHandler $handler,
    ) {
    }

    public function __invoke(int $eventId, string $orderShortId): LaravelResponse
    {
        try {
            $pdf = $this->handler->handle(
                eventId: $eventId,
                orderShortId: $orderShortId,
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
