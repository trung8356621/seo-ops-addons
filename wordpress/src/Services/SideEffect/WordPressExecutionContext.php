<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

interface WordPressExecutionContext
{
    public function origin(): string;

    public function correlationId(): string;

    public function articleId(): ?int;

    public function siteId(): ?int;

    public function actorId(): ?int;
}
