<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Data;

final class SettingDefinition
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $label,
        public readonly array $schema = [],
        public readonly mixed $default = null,
    ) {}
}
