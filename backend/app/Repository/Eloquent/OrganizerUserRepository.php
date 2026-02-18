<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerUserDomainObject;
use HiEvents\Models\OrganizerUser;
use HiEvents\Repository\Interfaces\OrganizerUserRepositoryInterface;

class OrganizerUserRepository extends BaseRepository implements OrganizerUserRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerUser::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerUserDomainObject::class;
    }

    /**
     * @return int[]
     */
    public function getOrganizerIdsByUserId(int $userId): array
    {
        return $this->model
            ->where('user_id', $userId)
            ->pluck('organizer_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }
}
