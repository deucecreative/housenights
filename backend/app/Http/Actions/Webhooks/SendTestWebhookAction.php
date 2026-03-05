<?php

namespace HiEvents\Http\Actions\Webhooks;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Webhook\SendTestWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendTestWebhookAction extends BaseAction
{
    public function __construct(
        private readonly SendTestWebhookHandler $sendTestWebhookHandler,
    )
    {
    }

    public function __invoke(int $eventId, int $webhookId, Request $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $eventType = $request->input('event_type');

        $result = $this->sendTestWebhookHandler->handle(
            eventId:   $eventId,
            webhookId: $webhookId,
            eventType: $eventType ?: null,
        );

        return $this->jsonResponse(
            data:       ['data' => $result],
            statusCode: ResponseCodes::HTTP_OK,
        );
    }
}
