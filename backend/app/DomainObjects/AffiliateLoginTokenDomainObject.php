<?php

namespace HiEvents\DomainObjects;

class AffiliateLoginTokenDomainObject extends Generated\AffiliateLoginTokenDomainObjectAbstract
{
    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
