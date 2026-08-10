<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Extension\Contracts;

interface MediaProcessorDriver
{
    public function id(): string;

    public function label(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function process(array $payload): array;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
