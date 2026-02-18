<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerUserRepositoryInterface;
use HiEvents\Resources\Event\EventResource;
use HiEvents\Services\Application\Handlers\Event\DTO\GetEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetEventsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetEventsAction extends BaseAction
{
    public function __construct(
        private readonly GetEventsHandler                 $getEventsHandler,
        private readonly OrganizerUserRepositoryInterface $organizerUserRepository,
    )
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->minimumAllowedRole(Role::ORGANIZER);

        $organizerIds = null;
        if ($this->getAuthenticatedUserRole() === Role::ORGANIZER) {
            $ids = $this->organizerUserRepository->getOrganizerIdsByUserId(
                $this->getAuthenticatedUser()->getId()
            );
            if (!empty($ids)) {
                $organizerIds = $ids;
            }
        }

        $events = $this->getEventsHandler->handle(
            GetEventsDTO::fromArray([
                'accountId' => $this->getAuthenticatedAccountId(),
                'queryParams' => $this->getPaginationQueryParams($request),
                'organizerIds' => $organizerIds,
            ]),
        );

        return $this->filterableResourceResponse(
            resource: EventResource::class,
            data: $events,
            domainObject: EventDomainObject::class,
        );
    }
}
