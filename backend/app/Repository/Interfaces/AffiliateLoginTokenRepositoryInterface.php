<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\AffiliateLoginTokenDomainObject;

interface AffiliateLoginTokenRepositoryInterface extends RepositoryInterface
{
    public function findValidByToken(string $token): ?AffiliateLoginTokenDomainObject;

    public function markAsUsed(int $tokenId): void;
}
