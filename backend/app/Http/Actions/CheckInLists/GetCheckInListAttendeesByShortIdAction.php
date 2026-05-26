<?php

namespace HiEvents\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeWithCheckInPublicResource;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeesPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetCheckInListAttendeesByShortIdAction extends BaseAction
{
    public function __construct(
        private readonly CheckInListRepositoryInterface         $checkInListRepository,
        private readonly GetCheckInListAttendeesPublicHandler   $getCheckInListAttendeesPublicHandler,
    )
    {
    }

    public function __invoke(string $checkInListShortId, Request $request): JsonResponse
    {
        $checkInList = $this->checkInListRepository->findFirstWhere([
            CheckInListDomainObjectAbstract::SHORT_ID => $checkInListShortId,
        ]);

        if (!$checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->isActionAuthorized($checkInList->getEventId(), EventDomainObject::class);

        $attendees = $this->getCheckInListAttendeesPublicHandler->handle(
            shortId: $checkInListShortId,
            queryParams: QueryParamsDTO::fromArray($request->query->all()),
            enforceActive: false,
        );

        return $this->resourceResponse(
            resource: AttendeeWithCheckInPublicResource::class,
            data: $attendees,
        );
    }
}
