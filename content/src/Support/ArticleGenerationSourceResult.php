<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Typed source cho {{input}} của article generation (first-run / rewrite / content retry).
 * raw_artifact = payload trước khi generate article (outline + vocabulary markers).
 */
final class ArticleGenerationSourceResult
{
    public const SOURCE_RUN_OUTLINE_ARTIFACT = 'run_outline_artifact';

    public const SOURCE_CANONICAL_OUTLINE_ARTIFACT = 'canonical_outline_artifact';

    public const SOURCE_RAW_ARTIFACT = 'raw_artifact';

    public const SOURCE_WORKFLOW_EDGE = 'workflow_edge';

    public const SOURCE_STATE_META = 'state_meta';

    public function __construct(
        public readonly string $rawArtifact,
        public readonly string $sourceType,
        public readonly string $outlineSection,
        public readonly string $writingInstructionsSection,
        public readonly bool $outlineMarkerFound,
        public readonly bool $writingInstructionsMarkerFound,
        public readonly string $artifactVersion,
        public readonly ?int $sourceRunId = null,
        public readonly ?int $sourceRunItemId = null,
        public readonly ?int $sourcePromptResultId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDebugVariables(): array
    {
        return [
            'article_generation_source' => $this->sourceType,
            'source_run_id' => $this->sourceRunId,
            'source_run_item_id' => $this->sourceRunItemId,
            'source_prompt_result_id' => $this->sourcePromptResultId,
            'outline_marker_found' => $this->outlineMarkerFound,
            'writing_instructions_marker_found' => $this->writingInstructionsMarkerFound,
            'artifact_version' => $this->artifactVersion,
        ];
    }
}
