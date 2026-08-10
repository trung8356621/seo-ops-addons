<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Contracts;

use Omnichannel\Addons\Agent\Extension\ExtensionContext;

interface ExtensionProvider
{
    public function id(): string;

    public function register(ExtensionContext $ctx): void;

    public function boot(ExtensionContext $ctx): void;
}
