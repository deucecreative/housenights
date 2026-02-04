<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Affiliates;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\SendAffiliateMagicLinkDTO;
use HiEvents\Services\Application\Handlers\Affiliate\SendAffiliateMagicLinkHandler;
use Illuminate\Http\JsonResponse;
use Throwable;

class SendAffiliateMagicLinkAction extends BaseAction
{
    public function __construct(
        private readonly SendAffiliateMagicLinkHandler $sendAffiliateMagicLinkHandler
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(int $eventId, int $affiliateId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $this->sendAffiliateMagicLinkHandler->handle(
                new SendAffiliateMagicLinkDTO(
                    affiliateId: $affiliateId,
                )
            );
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
            );
        }

        return $this->jsonResponse([
            'message' => __('Magic link sent successfully'),
        ]);
    }
}
