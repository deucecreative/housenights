<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Affiliates;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Affiliate\RequestAffiliateMagicLinkRequest;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\RequestAffiliateMagicLinkDTO;
use HiEvents\Services\Application\Handlers\Affiliate\RequestAffiliateMagicLinkHandler;
use Illuminate\Http\JsonResponse;

class RequestAffiliateMagicLinkAction extends BaseAction
{
    public function __construct(
        private readonly RequestAffiliateMagicLinkHandler $requestAffiliateMagicLinkHandler,
    ) {
    }

    public function __invoke(RequestAffiliateMagicLinkRequest $request): JsonResponse
    {
        $this->requestAffiliateMagicLinkHandler->handle(
            new RequestAffiliateMagicLinkDTO(
                email: $request->validated('email'),
            )
        );

        return $this->jsonResponse(
            data: [
                'message' => __('If you have an affiliate account with this email, we will send you an email with the details.'),
            ]
        );
    }
}
