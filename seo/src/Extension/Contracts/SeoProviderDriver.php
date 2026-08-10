<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Extension\Contracts;

interface SeoProviderDriver
{
    public function id(): string;

    public function label(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
