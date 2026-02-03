<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Affiliates;

use HiEvents\Exceptions\InvalidAffiliateMagicLinkException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Affiliate\DTO\GetAffiliateByMagicLinkDTO;
use HiEvents\Services\Application\Handlers\Affiliate\GetAffiliateByMagicLinkHandler;
use Illuminate\Http\JsonResponse;

class GetAffiliateByMagicLinkAction extends BaseAction
{
    public function __construct(
        private readonly GetAffiliateByMagicLinkHandler $getAffiliateByMagicLinkHandler
    ) {
    }

    public function __invoke(string $token): JsonResponse
    {
        try {
            $data = $this->getAffiliateByMagicLinkHandler->handle(
                new GetAffiliateByMagicLinkDTO(
                    token: $token,
                )
            );
        } catch (InvalidAffiliateMagicLinkException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
            );
        }

        return $this->jsonResponse([
            'affiliate' => [
                'id' => $data['affiliate']->getId(),
                'name' => $data['affiliate']->getName(),
                'code' => $data['affiliate']->getCode(),
                'total_sales' => $data['affiliate']->getTotalSales(),
                'total_sales_gross' => $data['affiliate']->getTotalSalesGross(),
            ],
            'event' => [
                'id' => $data['event']->getId(),
                'title' => $data['event']->getTitle(),
                'slug' => $data['event']->getSlug(),
                'affiliate_term' => $data['organizer']->getOrganizerSettings()?->getAffiliateTerm(),
            ],
            'orders' => $data['orders']->toArray(),
        ], wrapInData: true);
    }
}
