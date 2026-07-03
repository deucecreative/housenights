<?php

namespace HiEvents\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\Exports\PromoCodesExport;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportPromoCodesAction extends BaseAction
{
    public function __construct(
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly PromoCodesExport             $export,
    )
    {
    }

    public function __invoke(int $eventId): BinaryFileResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $promoCodes = $this->promoCodeRepository->findWhere([
            PromoCodeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        return Excel::download(
            $this->export->withData($promoCodes),
            'promo-codes.xlsx'
        );
    }
}
