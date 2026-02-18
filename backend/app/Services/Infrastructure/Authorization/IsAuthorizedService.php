<?php

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerUserRepositoryInterface;
use HiEvents\Repository\Interfaces\TaxAndFeeRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;

readonly class IsAuthorizedService
{
    public function __construct(
        private Application                       $app,
        private AccountUserRepositoryInterface    $accountUserRepository,
        private OrganizerUserRepositoryInterface  $organizerUserRepository,
        private AuthManager                       $auth,
    )
    {
    }

    /**
     * @todo This is a very simplistic way of handling roles. Currently we have an ADMIN and ORGANIZER role, but we
     *      will have a more granular approach to roles.
     */
    public function validateUserRole(Role $minimumRole, UserDomainObject $authUser): void
    {
        if ($minimumRole === Role::ADMIN
            && in_array($authUser->getCurrentAccountUser()->getRole(), [Role::SUPERADMIN->name, Role::ADMIN->name], true) === false
        ) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($minimumRole === Role::SUPERADMIN && $authUser->getCurrentAccountUser()->getRole() !== Role::SUPERADMIN->name) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }
    }

    public function isActionAuthorized(
        int              $entityId,
        string           $entityType,
        UserDomainObject $authUser,
        int              $authAccountId,
        Role             $minimumRole
    ): void
    {
        $this->validateUserStatus($authUser);
        $this->validateUserRole($minimumRole, $authUser);

        $repository = match ($entityType) {
            EventDomainObject::class => $this->app->make(EventRepositoryInterface::class),
            AccountDomainObject::class => $this->app->make(AccountRepositoryInterface::class),
            UserDomainObject::class => $this->app->make(UserRepositoryInterface::class),
            TaxAndFeesDomainObject::class => $this->app->make(TaxAndFeeRepositoryInterface::class),
            OrganizerDomainObject::class => $this->app->make(OrganizerRepositoryInterface::class),
            ImageDomainObject::class => $this->app->make(ImageRepositoryInterface::class),
        };

        $entity = $repository->findById($entityId);

        $result = match ($entityType) {
            EventDomainObject::class,
            ImageDomainObject::class,
            OrganizerDomainObject::class => $entity?->getAccountId() === $authAccountId,
            AccountDomainObject::class => $entity?->getId() === $authAccountId,
            UserDomainObject::class => $this->validateUserUpdate($entity, $authAccountId),
            TaxAndFeesDomainObject::class => $this->validateTax($entity, $authAccountId),
        };

        if (!$result) {
            throw new UnauthorizedException();
        }

        // Additional per-organizer check for ORGANIZER-role users
        $userRole = $authUser->getCurrentAccountUser()?->getRole();
        if ($userRole === Role::ORGANIZER->name) {
            $this->validateOrganizerAccess($entityId, $entityType, $authUser, $entity);
        }
    }

    private function validateUserUpdate(?UserDomainObject $user, int $authAccountId): bool
    {
        if ($user === null) {
            return false;
        }

        $accountUser = $this->accountUserRepository->findFirstWhere([
            'account_id' => $authAccountId,
            'user_id' => $user->getId(),
        ]);

        return $accountUser !== null;
    }

    private function validateTax(?TaxAndFeesDomainObject $taxOrFee, int $authAccountId): bool
    {
        if ($taxOrFee === null) {
            return false;
        }

        if ($taxOrFee->getAccountId() === $authAccountId) {
            return true;
        }

        return false;
    }

    private function validateUserStatus(UserDomainObject $authUser): void
    {
        if ($authUser->getCurrentAccountUser()?->getStatus() !== UserStatus::ACTIVE->name) {
            // Log the user out if their account is not active. This can happen if a user is
            // deactivated while they are logged in.
            $this->auth->logout();
            throw new UnauthorizedException(__('Your account is not active.'));
        }
    }

    private function validateOrganizerAccess(int $entityId, string $entityType, UserDomainObject $authUser, $entity): void
    {
        $assignedOrganizerIds = $this->organizerUserRepository->getOrganizerIdsByUserId($authUser->getId());

        if (empty($assignedOrganizerIds)) {
            return; // No assignments = unrestricted access
        }

        $organizerIdToCheck = match ($entityType) {
            OrganizerDomainObject::class => $entityId,
            EventDomainObject::class => $entity?->getOrganizerId(),
            default => null,
        };

        if ($organizerIdToCheck !== null && !in_array($organizerIdToCheck, $assignedOrganizerIds, true)) {
            throw new UnauthorizedException();
        }
    }
}
