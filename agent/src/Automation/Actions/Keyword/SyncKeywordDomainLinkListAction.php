<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Keyword;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\DomainLinkListKeywordSyncService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Sync one keyword phrase into domain link list (idempotent upsert/remove).
 */
final class SyncKeywordDomainLinkListAction implements BusinessAction
{
    public function __construct(
        private readonly DomainLinkListKeywordSyncService $linkList,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'keyword.domain_link_list.sync',
            name: 'Sync keyword domain link list',
            description: 'Upsert or remove keyword phrase in domain link list. Idempotent for same phrase+url.',
            module: 'keyword',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'keyword_id' => ['type' => 'integer', 'required' => false],
                'site_id' => ['type' => 'integer', 'required' => false],
                'phrase' => ['type' => 'string', 'required' => false],
                'target_url' => ['type' => 'string', 'required' => false],
                'previous_phrase' => ['type' => 'string', 'required' => false],
                'operation' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'site_id' => ['type' => 'integer'],
                'phrase' => ['type' => 'string'],
                'operation' => ['type' => 'string'],
                'changed' => ['type' => 'boolean'],
            ],
            idempotent: true,
            lockScope: 'keyword',
            emittedEvents: ['keyword.domain_link_list_synced'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $operation = strtolower(trim((string) ($input['operation'] ?? 'upsert')));
        $keywordId = (int) ($input['keyword_id'] ?? 0);
        $keyword = $keywordId > 0 ? Keyword::query()->find($keywordId) : null;

        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);
        if ($siteId <= 0) {
            $siteId = (int) (SeoAccessControl::globalSiteId() ?? ($keyword?->resolveSiteId() ?? 0));
        }

        $phrase = trim((string) ($input['phrase'] ?? ($keyword?->phrase ?? '')));
        $previousPhrase = trim((string) ($input['previous_phrase'] ?? ''));
        $targetUrl = trim((string) ($input['target_url'] ?? ''));

        if ($keyword instanceof Keyword && $targetUrl === '' && $operation !== 'remove') {
            $targetUrl = trim((string) ($keyword->targetUrlForSite($siteId) ?? ''));
        }

        if ($siteId <= 0) {
            return ActionResult::failure('site_required', 'site_id is required for domain link list sync.');
        }

        if ($keyword instanceof Keyword && $keyword->type !== Keyword::TYPE_NORMAL) {
            return ActionResult::success(
                output: [
                    'site_id' => $siteId,
                    'phrase' => $phrase,
                    'operation' => 'skipped_non_normal',
                    'changed' => false,
                ],
            );
        }

        $changed = false;

        try {
            if ($previousPhrase !== '' && $previousPhrase !== $phrase) {
                $changed = $this->linkList->removeLinkFromDomainContext($siteId, $previousPhrase) || $changed;
            }

            if ($operation === 'remove' || $phrase === '' || $targetUrl === '') {
                if ($phrase !== '') {
                    $changed = $this->linkList->removeLinkFromDomainContext($siteId, $phrase) || $changed;
                }
                $operation = 'remove';
            } else {
                $changed = $this->linkList->upsertLinkInDomainContext($siteId, $phrase, $targetUrl) || $changed;
                $operation = 'upsert';
            }
        } catch (\Throwable $exception) {
            return ActionResult::failure('domain_link_list_sync_failed', $exception->getMessage());
        }

        return ActionResult::success(
            output: [
                'site_id' => $siteId,
                'phrase' => $phrase,
                'operation' => $operation,
                'changed' => $changed,
                'keyword_id' => $keywordId > 0 ? $keywordId : null,
            ],
            changed: $changed ? ['domain_link_list'] : [],
        );
    }
}
