<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts;

interface AiProviderDriver
{
    public function id(): string;

    public function label(): string;

    public function supportsChat(): bool;

    public function supportsImage(): bool;

    public function supportsEmbedding(): bool;

    public function supportsModeration(): bool;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
