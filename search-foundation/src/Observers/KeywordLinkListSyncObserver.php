<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Observers;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPhraseUpdateService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\DB;

/**
 * Technical invariants only — domain link list sync owned by Automation Rule
 * on keyword.saved → keyword.domain_link_list.sync.
 *
 * `updating` stays in-transaction (capture previous phrase).
 * Side effects run afterCommit on the keyword connection.
 */
final class KeywordLinkListSyncObserver
{
    private ?string $previousPhrase = null;

    public function updating(Keyword $keyword): void
    {
        if ($keyword->isDirty('phrase')) {
            $this->previousPhrase = trim((string) $keyword->getOriginal('phrase'));
        }
    }

    public function saved(Keyword $keyword): void
    {
        if (! \Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation::allowsKeywordObserverSync()) {
            $this->previousPhrase = null;

            return;
        }

        $previousPhrase = $this->previousPhrase;
        $this->previousPhrase = null;
        $keywordId = (int) $keyword->id;

        $this->afterKeywordCommit($keyword, function () use ($keyword, $previousPhrase, $keywordId): void {
            $fresh = Keyword::query()->find($keywordId);
            if (! $fresh instanceof Keyword) {
                return;
            }

            if ($previousPhrase !== null && $previousPhrase !== '') {
                app(KeywordPhraseUpdateService::class)->propagate($fresh, $previousPhrase);
            }

            if ($fresh->type !== Keyword::TYPE_NORMAL) {
                return;
            }

            $siteId = (int) (SeoAccessControl::globalSiteId() ?? $fresh->resolveSiteId() ?? 0);
            $phrase = trim((string) ($fresh->phrase ?? ''));
            if ($siteId <= 0 || $phrase === '') {
                return;
            }

            $targetUrl = trim((string) ($fresh->targetUrlForSite($siteId) ?? ''));
            $operation = $targetUrl === '' ? 'remove' : 'upsert';

            app(BusinessHookEmitter::class)->keywordSaved($fresh, [
                'keyword_id' => (int) $fresh->id,
                'site_id' => $siteId,
                'phrase' => $phrase,
                'target_url' => $targetUrl,
                'previous_phrase' => (string) ($previousPhrase ?? ''),
                'operation' => $operation,
            ]);
        });
    }

    public function deleted(Keyword $keyword): void
    {
        if (! \Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation::allowsKeywordObserverSync()) {
            return;
        }

        if ($keyword->type !== Keyword::TYPE_NORMAL) {
            return;
        }

        $phrase = trim((string) ($keyword->phrase ?? ''));
        $siteId = (int) ($keyword->resolveSiteId() ?? 0);
        if ($phrase === '' || $siteId <= 0) {
            return;
        }

        $payload = [
            'keyword_id' => (int) $keyword->id,
            'site_id' => $siteId,
            'phrase' => $phrase,
            'target_url' => '',
            'previous_phrase' => '',
            'operation' => 'remove',
        ];

        $this->afterKeywordCommit($keyword, function () use ($keyword, $payload): void {
            app(BusinessHookEmitter::class)->keywordSaved($keyword, $payload);
        });
    }

    private function afterKeywordCommit(Keyword $keyword, callable $callback): void
    {
        $connection = $keyword->getConnectionName() ?: $keyword->getConnection()->getName();
        $db = DB::connection($connection);

        if ($db->transactionLevel() > 0) {
            $db->afterCommit($callback);

            return;
        }

        $callback();
    }
}
