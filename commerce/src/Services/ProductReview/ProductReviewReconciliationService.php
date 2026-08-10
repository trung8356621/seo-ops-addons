<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Commerce\Enums\ProductReviewPublishIntent;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewReconciliationResult;

/**
 * Shared reconciliation for editor endpoint + artisan command (idempotent).
 */
final class ProductReviewReconciliationService
{
    public const GENERATED_RULE = 'publish-generated-product-reviews-to-wordpress';

    public const PENDING_RULE = 'publish-pending-product-reviews-after-article-sync';

    public function __construct(
        private readonly LegacyProductReviewStateNormalizer $legacyNormalizer,
        private readonly PendingProductReviewReconciler $reconciler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcileForArticle(
        SeoArticle $article,
        ?int $actorId = null,
        bool $dryRun = false,
    ): array {
        $article = $article->fresh() ?? $article;
        $legacy = $this->legacyNormalizer->normalizeForArticle($article);

        $pendingEnabled = $this->isRuleEnabled(self::PENDING_RULE);
        $generatedEnabled = $this->isRuleEnabled(self::GENERATED_RULE);
        $automationEnabled = $pendingEnabled || $generatedEnabled;

        $settings = $this->settingsFromRule(self::PENDING_RULE);
        if ($settings === [] && $generatedEnabled) {
            $settings = $this->settingsFromRule(self::GENERATED_RULE);
        }
        if ($settings === []) {
            $settings = ProductReviewDelaySettings::normalizeSettings([]);
        }

        if (! $automationEnabled) {
            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                $waiting = $this->reconciler->reconcileForArticle(
                    article: $article,
                    settings: $settings,
                    reviewIds: null,
                    publishIntent: ProductReviewPublishIntent::PublishAfterArticle->value,
                    actorId: $actorId,
                    dryRun: true,
                );

                return $this->report($article, $legacy, $waiting, automationDisabled: true);
            }

            $preview = $this->reconciler->reconcileForArticle(
                article: $article,
                settings: $settings,
                reviewIds: null,
                publishIntent: ProductReviewPublishIntent::PublishAfterArticle->value,
                actorId: $actorId,
                dryRun: true,
            );

            return $this->report($article, $legacy, $preview, automationDisabled: true);
        }

        if ($dryRun) {
            $result = $this->reconciler->reconcileForArticle(
                article: $article,
                settings: $settings,
                reviewIds: null,
                publishIntent: ProductReviewPublishIntent::PublishAfterArticle->value,
                actorId: $actorId,
                dryRun: true,
            );

            return $this->report($article, $legacy, $result, automationDisabled: false);
        }

        $result = $this->reconciler->reconcileForArticle(
            article: $article,
            settings: $settings,
            reviewIds: null,
            publishIntent: ProductReviewPublishIntent::PublishAfterArticle->value,
            actorId: $actorId,
            dryRun: false,
        );

        return $this->report($article, $legacy, $result, automationDisabled: false);
    }

    /**
     * @param  array{repaired: int, review_ids: list<int>}  $legacy
     * @return array<string, mixed>
     */
    private function report(
        SeoArticle $article,
        array $legacy,
        ProductReviewReconciliationResult $result,
        bool $automationDisabled,
    ): array {
        return [
            'article_id' => (int) $article->id,
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
            'review_count' => count($result->foundReviewIds),
            'legacy_repaired' => (int) $legacy['repaired'],
            'queued' => count($result->queuedReviewIds),
            'queued_review_ids' => $result->queuedReviewIds,
            'already_scheduled' => count($result->skippedAlreadyScheduled),
            'already_published' => count($result->skippedAlreadyPublished),
            'waiting_for_article' => count($result->waitingForArticle),
            'invalid' => count($result->invalid),
            'automation_disabled' => $automationDisabled,
            'outcome' => $result->outcome,
            'message' => $result->message,
        ];
    }

    private function isRuleEnabled(string $code): bool
    {
        try {
            $rule = AutomationRule::query()->where('code', $code)->first();

            return $rule instanceof AutomationRule && (bool) $rule->is_enabled;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFromRule(string $code): array
    {
        try {
            $rule = AutomationRule::query()->where('code', $code)->first();
            if (! $rule instanceof AutomationRule) {
                return [];
            }
            $rule->loadMissing('actions');
            foreach ($rule->actions as $action) {
                $settings = is_array($action->settings) ? $action->settings : [];
                if ($settings !== []) {
                    return ProductReviewDelaySettings::normalizeSettings($settings);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }
}
