<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

final class SettingsTransferRegistry
{
    /**
     * @param  list<PortableSettingsSection>  $sections
     */
    public function __construct(
        private readonly array $sections,
    ) {}

    /**
     * @return list<PortableSettingsSection>
     */
    public function all(): array
    {
        return $this->sections;
    }

    public function get(string $key): ?PortableSettingsSection
    {
        foreach ($this->sections as $section) {
            if ($section->key() === $key) {
                return $section;
            }
        }

        return null;
    }
}
