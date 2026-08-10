<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Contracts;

interface PipelineStepDriver
{
    public function id(): string;

    public function label(): string;

    /**
     * outline|article|translate|review|image|seo_audit|custom
     */
    public function stage(): string;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
