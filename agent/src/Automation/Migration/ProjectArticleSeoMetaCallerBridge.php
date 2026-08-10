<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleSeoMetaUpdateParityPlanner;
use Illuminate\Support\Str;

/**
 * Group 2 — article.seo_meta.update. Wired via PromptTestPublishService.
 */
final class ProjectArticleSeoMetaCallerBridge
{
    public function __construct(
        private readonly AutomationCallerMigrator $migrator,
        private readonly ParitySnapshotNormalizer $parityNormalizer,
        private readonly ArticleSeoMetaUpdateParityPlanner $planner,
        private readonly ArticleActionOutputNormalizer $outputNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metaState  Snapshot đã resolve
     * @param  callable(): array<string, mixed>  $legacyWrite
     * @param  callable(): ActionResult  $actionWrite
     * @return array<string, mixed>|ActionResult
     */
    public function run(
        array $input,
        array $metaState,
        callable $legacyWrite,
        callable $actionWrite,
        ?string $correlationId = null,
    ): mixed {
        $normalizer = $this->parityNormalizer;
        $planner = $this->planner;
        $outputs = $this->outputNormalizer;

        $result = $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_ARTICLE_SEO_META_UPDATE,
            legacyWrite: $legacyWrite,
            actionWrite: $actionWrite,
            parityExpected: static fn (): array => $planner->plan($input, $metaState),
            normalizeLegacy: static function (mixed $v) use ($normalizer, $outputs): array {
                $raw = $v instanceof ActionResult ? $v->output : (is_array($v) ? $v : []);

                return $normalizer->articleSeoMeta($outputs->seoMeta($raw));
            },
            normalizeExpected: static fn (array $v): array => $normalizer->articleSeoMeta($outputs->seoMeta($v)),
            actionKey: 'article.seo_meta.update',
            correlationId: $correlationId ?? Str::uuid()->toString(),
        );

        if ($result instanceof ActionResult) {
            return $outputs->seoMeta($result->output);
        }

        return $outputs->seoMeta(is_array($result) ? $result : []);
    }
}
