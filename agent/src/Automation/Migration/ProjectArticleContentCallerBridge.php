<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleContentUpdateParityPlanner;
use Illuminate\Support\Str;

/**
 * Group 2 — article.content.update. Wired via PromptTestPublishService.
 */
final class ProjectArticleContentCallerBridge
{
    public function __construct(
        private readonly AutomationCallerMigrator $migrator,
        private readonly ParitySnapshotNormalizer $parityNormalizer,
        private readonly ArticleContentUpdateParityPlanner $planner,
        private readonly ArticleActionOutputNormalizer $outputNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $articleState  Snapshot đã resolve
     * @param  callable(): array<string, mixed>  $legacyWrite
     * @param  callable(): ActionResult  $actionWrite
     * @return array<string, mixed>|ActionResult
     */
    public function run(
        array $input,
        array $articleState,
        callable $legacyWrite,
        callable $actionWrite,
        ?string $correlationId = null,
    ): mixed {
        $normalizer = $this->parityNormalizer;
        $planner = $this->planner;
        $outputs = $this->outputNormalizer;

        $result = $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE,
            legacyWrite: $legacyWrite,
            actionWrite: $actionWrite,
            parityExpected: static fn (): array => $planner->plan($input, $articleState),
            normalizeLegacy: static function (mixed $v) use ($normalizer, $outputs): array {
                $raw = $v instanceof ActionResult ? $v->output : (is_array($v) ? $v : []);

                return $normalizer->articleContent($outputs->content($raw));
            },
            normalizeExpected: static fn (array $v): array => $normalizer->articleContent($outputs->content($v)),
            actionKey: 'article.content.update',
            correlationId: $correlationId ?? Str::uuid()->toString(),
        );

        if ($result instanceof ActionResult) {
            return $outputs->content($result->output);
        }

        return $outputs->content(is_array($result) ? $result : []);
    }
}
