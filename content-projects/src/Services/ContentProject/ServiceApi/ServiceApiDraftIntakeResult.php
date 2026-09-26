<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi;

/**
 * Aggregate result for Service API Draft intake (one site, bulk items).
 */
final class ServiceApiDraftIntakeResult
{
    /**
     * @param  list<ServiceApiDraftIntakeItemResult>  $items
     */
    public function __construct(
        public readonly string $draftRef,
        public readonly string $siteRef,
        public readonly int $submitted,
        public readonly int $added,
        public readonly int $alreadyInDraft,
        public readonly int $failed,
        public readonly array $items,
        public readonly bool $idempotentReplay = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draft_ref' => $this->draftRef,
            'site_ref' => $this->siteRef,
            'submitted' => $this->submitted,
            'added' => $this->added,
            'already_in_draft' => $this->alreadyInDraft,
            'failed' => $this->failed,
            'items' => array_map(
                static fn (ServiceApiDraftIntakeItemResult $item): array => $item->toArray(),
                $this->items,
            ),
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }
}
