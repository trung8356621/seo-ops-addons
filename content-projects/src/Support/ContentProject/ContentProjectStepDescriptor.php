<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Descriptor bước workflow có thể rerun — identity ổn định từ node snapshot.
 *
 * @phpstan-type SourceRequirement list<string>
 */
final class ContentProjectStepDescriptor
{
    /**
     * @param  list<string>  $sourceRequirements
     * @param  list<string>  $downstreamNodeIds
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly ?string $executionRole,
        public readonly ?string $postType,
        public readonly ?string $hookKey,
        public readonly string $label,
        public readonly string $kind,
        public readonly int $sequence,
        public readonly bool $rerunnable,
        public readonly array $sourceRequirements,
        public readonly array $downstreamNodeIds,
        public readonly ?int $promptId = null,
        public readonly string $title = '',
        public readonly ?string $unavailableReason = null,
    ) {}

    /**
     * @return array{
     *     node_id: string,
     *     execution_role: ?string,
     *     post_type: ?string,
     *     hook_key: ?string,
     *     label: string,
     *     kind: string,
     *     sequence: int,
     *     rerunnable: bool,
     *     source_requirements: list<string>,
     *     downstream_nodes: list<string>,
     *     prompt_id: ?int,
     *     title: string,
     *     unavailable_reason: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'execution_role' => $this->executionRole,
            'post_type' => $this->postType,
            'hook_key' => $this->hookKey,
            'label' => $this->label,
            'kind' => $this->kind,
            'sequence' => $this->sequence,
            'rerunnable' => $this->rerunnable,
            'source_requirements' => $this->sourceRequirements,
            'downstream_nodes' => $this->downstreamNodeIds,
            'prompt_id' => $this->promptId,
            'title' => $this->title,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
