<?php

namespace HiEvents\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Resources\CheckInList\CheckInListResourcePublic;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListPublicHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetCheckInListByShortIdAction extends BaseAction
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly GetCheckInListPublicHandler    $getCheckInListPublicHandler,
    )
    {
    }

    public function __invoke(string $checkInListShortId): JsonResponse
    {
        $checkInList = $this->checkInListRepository->findFirstWhere([
            CheckInListDomainObjectAbstract::SHORT_ID => $checkInListShortId,
        ]);

        if (!$checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->isActionAuthorized($checkInList->getEventId(), EventDomainObject::class);

        $result = $this->getCheckInListPublicHandler->handle($checkInListShortId);

        return $this->resourceResponse(
            resource: CheckInListResourcePublic::class,
            data: $result,
        );
    }
}
