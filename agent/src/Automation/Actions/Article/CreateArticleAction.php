<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Article;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-only article create. Idempotent theo source identity (origin_type + origin_id), không theo title.
 */
final class CreateArticleAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleCreateOriginResolver $originResolver,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.create',
            name: 'Create article (local)',
            description: 'Create SeoArticle local-only. Never calls WordPress.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'site_id' => ['type' => 'integer', 'required' => true],
                'title' => ['type' => 'string', 'required' => false],
                'keyword' => ['type' => 'string', 'required' => false],
                'post_type' => ['type' => 'string', 'required' => false],
                'language' => ['type' => 'string', 'required' => false],
                'origin_type' => ['type' => 'string', 'required' => false],
                'origin_id' => ['type' => 'integer', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'site_id' => ['type' => 'integer'],
                'post_type' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'deduplicated' => ['type' => 'boolean'],
            ],
            idempotent: true,
            supportsDryRun: true,
            emittedEvents: ['article.created'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);
        if ($siteId <= 0) {
            return ActionResult::failure('invalid_input', 'site_id is required.');
        }

        $keyword = trim((string) ($input['keyword'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $keyword !== '' ? $keyword : 'Untitled';
        }

        $postType = SeoProjectTask::normalizePostType((string) ($input['post_type'] ?? 'article'));
        $language = ArticleLanguageCode::normalizeForStorage((string) ($input['language'] ?? 'vi'));
        $originType = isset($input['origin_type']) ? trim((string) $input['origin_type']) : '';
        $originId = isset($input['origin_id']) ? (int) $input['origin_id'] : 0;

        $existing = $this->originResolver->findExisting(
            $originType !== '' ? $originType : null,
            $originId > 0 ? $originId : null,
            $siteId,
            $postType,
        );

        if ($existing !== null) {
            if ($context->dryRun) {
                return ActionResult::success(
                    output: array_merge($existing, ['dry_run' => true]),
                    status: ActionRunStatus::DryRun,
                );
            }

            return ActionResult::success(
                output: $existing,
                changed: [],
            );
        }

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'site_id' => $siteId,
                    'post_type' => $postType,
                    'status' => 'draft',
                    'deduplicated' => false,
                    'dry_run' => true,
                    'would_create' => true,
                ],
                status: ActionRunStatus::DryRun,
            );
        }

        $slugSource = $keyword !== '' ? $keyword : $title;
        $slug = Str::slug($slugSource);
        $actorId = $context->actorId ?? (auth()->id() !== null ? (int) auth()->id() : null);

        /** @var array{article_id: int, site_id: int, post_type: string, status: string, title: string, deduplicated: bool} $output */
        $output = DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $title,
            $slug,
            $postType,
            $language,
            $actorId,
            $keyword,
            $originType,
            $originId,
        ): array {
            // Re-check inside txn for race on project task origin.
            $again = $this->originResolver->findExisting(
                $originType !== '' ? $originType : null,
                $originId > 0 ? $originId : null,
                $siteId,
                $postType,
            );
            if ($again !== null) {
                return $again;
            }

            $article = SeoArticle::query()->create([
                'site_id' => $siteId,
                'user_id' => $actorId,
                'type' => $postType,
                'title' => $title,
                'slug' => $slug !== '' ? $slug : null,
                'status' => 'draft',
                'body' => '',
                'language' => $language,
            ]);

            if ($keyword !== '') {
                KeywordFocusAttach::attachMainKeyword($article, $siteId, $keyword);
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'seo_focus_keyword'],
                    ['meta_value' => $keyword],
                );
            }

            $article->articleMetas()->where('meta_key', 'wp_post_type')->delete();

            if ($originType !== '' && $originId > 0) {
                $this->originResolver->persistOriginMeta($article, $originType, $originId);
                if ($originType === ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK) {
                    $this->originResolver->attachToProjectTaskIfNeeded($originId, (int) $article->id);
                }
            }

            return [
                'article_id' => (int) $article->id,
                'site_id' => $siteId,
                'post_type' => $postType,
                'status' => 'draft',
                'title' => $title,
                'deduplicated' => false,
            ];
        });

        if ($output['deduplicated'] ?? false) {
            return ActionResult::success(output: $output, changed: []);
        }

        return ActionResult::success(
            output: $output,
            events: [
                ActionSupport::articleEvent('article.created', $context, $output['article_id'], [
                    'site_id' => $output['site_id'],
                    'post_type' => $output['post_type'],
                    'origin_type' => $originType !== '' ? $originType : null,
                    'origin_id' => $originId > 0 ? $originId : null,
                ]),
            ],
            changed: ['article'],
        );
    }
}
