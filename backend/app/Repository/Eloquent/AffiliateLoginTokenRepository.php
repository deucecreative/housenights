<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateLoginTokenDomainObject;
use HiEvents\DomainObjects\Generated\AffiliateLoginTokenDomainObjectAbstract;
use HiEvents\Models\AffiliateLoginToken;
use HiEvents\Repository\Interfaces\AffiliateLoginTokenRepositoryInterface;

class AffiliateLoginTokenRepository extends BaseRepository implements AffiliateLoginTokenRepositoryInterface
{
    protected function getModel(): string
    {
        return AffiliateLoginToken::class;
    }

    public function getDomainObject(): string
    {
        return AffiliateLoginTokenDomainObject::class;
    }

    public function findValidByToken(string $token): ?AffiliateLoginTokenDomainObject
    {
        return $this->findFirstWhere([
            AffiliateLoginTokenDomainObjectAbstract::TOKEN => $token,
            [AffiliateLoginTokenDomainObjectAbstract::EXPIRES_AT, '>', Carbon::now()],
            [AffiliateLoginTokenDomainObjectAbstract::USED_AT, '=', null],
        ]);
    }

    public function markAsUsed(int $tokenId): void
    {
        $this->updateWhere(
            attributes: [AffiliateLoginTokenDomainObjectAbstract::USED_AT => Carbon::now()],
            where: [AffiliateLoginTokenDomainObjectAbstract::ID => $tokenId]
        );
    }
}
