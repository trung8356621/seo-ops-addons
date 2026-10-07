<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\DTO\MatchResearch;

final class MatchResearchCapabilities
{
    public function __construct(
        public readonly bool $canMatch,
        public readonly bool $canTag,
        public readonly bool $canExclude,
        public readonly bool $canRank,
    ) {}

    /** @param array{can_match?:bool,can_tag?:bool,can_exclude?:bool,can_rank?:bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['can_match'] ?? false),
            (bool) ($data['can_tag'] ?? false),
            (bool) ($data['can_exclude'] ?? false),
            (bool) ($data['can_rank'] ?? false),
        );
    }

    /** @return array{can_match:bool,can_tag:bool,can_exclude:bool,can_rank:bool} */
    public function toArray(): array
    {
        return [
            'can_match' => $this->canMatch,
            'can_tag' => $this->canTag,
            'can_exclude' => $this->canExclude,
            'can_rank' => $this->canRank,
        ];
    }
}
