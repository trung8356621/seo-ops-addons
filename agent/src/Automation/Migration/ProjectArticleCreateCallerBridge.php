<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleCreateParityPlanner;
use Illuminate\Support\Str;

/**
 * Group 2 — article.create. Wired via CreateArticlesFromTaskService.
 */
final class ProjectArticleCreateCallerBridge
{
    public function __construct(
        private readonly AutomationCallerMigrator $migrator,
        private readonly ParitySnapshotNormalizer $parityNormalizer,
        private readonly ArticleCreateParityPlanner $planner,
        private readonly ArticleActionOutputNormalizer $outputNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  callable(): array<string, mixed>  $legacyWrite
     * @param  callable(): ActionResult  $actionWrite
     * @param  array<string, mixed>|null  $existingByOrigin  Snapshot đã resolve (không query trong bridge prep)
     * @return array<string, mixed>|ActionResult
     */
    public function run(
        array $input,
        callable $legacyWrite,
        callable $actionWrite,
        ?array $existingByOrigin = null,
        ?string $correlationId = null,
    ): mixed {
        $normalizer = $this->parityNormalizer;
        $planner = $this->planner;
        $outputs = $this->outputNormalizer;

        $result = $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_ARTICLE_CREATE,
            legacyWrite: $legacyWrite,
            actionWrite: $actionWrite,
            parityExpected: static fn (): array => $planner->plan($input, $existingByOrigin),
            normalizeLegacy: static function (mixed $v) use ($normalizer, $outputs): array {
                $raw = $v instanceof ActionResult ? $v->output : (is_array($v) ? $v : []);

                return $normalizer->articleCreate($outputs->create($raw));
            },
            normalizeExpected: static fn (array $v): array => $normalizer->articleCreate($outputs->create($v)),
            actionKey: 'article.create',
            correlationId: $correlationId ?? Str::uuid()->toString(),
        );

        if ($result instanceof ActionResult) {
            return $outputs->create($result->output);
        }

        return $outputs->create(is_array($result) ? $result : []);
    }
}
