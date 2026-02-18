<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerUserDomainObject;
use HiEvents\Repository\Eloquent\BaseRepository;

/**
 * @extends BaseRepository<OrganizerUserDomainObject>
 */
interface OrganizerUserRepositoryInterface extends RepositoryInterface
{
    /**
     * @return int[]
     */
    public function getOrganizerIdsByUserId(int $userId): array;
}
