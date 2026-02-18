<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerUserRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerResource;
use Illuminate\Http\JsonResponse;

class GetOrganizersAction extends BaseAction
{
    public function __construct(
        private readonly OrganizerRepositoryInterface     $organizerRepository,
        private readonly OrganizerUserRepositoryInterface $organizerUserRepository,
    )
    {
    }

    public function __invoke(): JsonResponse
    {
        $userRole = $this->getAuthenticatedUserRole();
        $accountId = $this->getAuthenticatedAccountId();

        if ($userRole === Role::ORGANIZER) {
            $organizerIds = $this->organizerUserRepository->getOrganizerIdsByUserId(
                $this->getAuthenticatedUser()->getId()
            );

            if (!empty($organizerIds)) {
                $organizers = $this->organizerRepository
                    ->loadRelation(ImageDomainObject::class)
                    ->findWhereIn('id', $organizerIds, [
                        'account_id' => $accountId,
                    ]);
            } else {
                $organizers = $this->organizerRepository
                    ->loadRelation(ImageDomainObject::class)
                    ->findwhere([
                        'account_id' => $accountId,
                    ]);
            }
        } else {
            $organizers = $this->organizerRepository
                ->loadRelation(ImageDomainObject::class)
                ->findwhere([
                    'account_id' => $accountId,
                ]);
        }

        return $this->resourceResponse(
            resource: OrganizerResource::class,
            data: $organizers,
        );
    }
}
