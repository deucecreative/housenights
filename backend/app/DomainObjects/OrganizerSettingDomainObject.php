<?php

namespace HiEvents\DomainObjects;

use BackedEnum;
use UnitEnum;

class OrganizerSettingDomainObject extends Generated\OrganizerSettingDomainObjectAbstract
{
    protected ?string $affiliate_term = null;

    public function getAffiliateTerm(): ?string
    {
        return $this->affiliate_term;
    }

    public function setAffiliateTerm(?string $affiliate_term): self
    {
        $this->affiliate_term = $affiliate_term;
        return $this;
    }

    public function getSocialMediaHandle(string $platform): ?string
    {
        $handles = $this->getSocialMediaHandles();

        return $handles[$platform] ?? null;
    }

    public function getHomepageThemeSetting(string $key, string $default = ''): ?string
    {
        $settings = $this->getHomepageThemeSettings();

        if (isset($settings[$key]) && ($settings[$key] instanceof UnitEnum)) {
            return $settings[$key] instanceof BackedEnum
                ? $settings[$key]->value
                : $settings[$key]->name;
        }

        return $settings[$key] ?? $default;
    }
}
