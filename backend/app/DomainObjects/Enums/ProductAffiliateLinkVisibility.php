<?php

namespace HiEvents\DomainObjects\Enums;

enum ProductAffiliateLinkVisibility: string
{
    use BaseEnum;

    case SHOW_ALWAYS = 'SHOW_ALWAYS';
    case AFFILIATE_ONLY = 'AFFILIATE_ONLY';
    case NORMAL_ONLY = 'NORMAL_ONLY';
}
