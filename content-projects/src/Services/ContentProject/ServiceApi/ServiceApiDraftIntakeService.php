<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Canonical Service API application adapter for Shared Planning Draft intake.
 * HTTP controller validates transport; this class owns orchestration.
 */
class ServiceApiDraftIntakeService
{
    public const ACTION = 'content_project.draft.intake';

    public const MAX_BATCH = 100;

    public const SCOPE = 'content-projects:draft:write';

    /** @var list<string> */
    public const ALLOWED_TYPES = ['new', 'rewrite'];

    /** @var list<string> */
    public const ALLOWED_SOURCE_TYPES = [
        'agent',
        'seo_audit',
        'draft_audit',
        'gsc',
        'manual_api',
    ];

    /** @var list<string> */
    private const TOP_LEVEL_FIELDS = ['site_id', 'items'];

    /** @var list<string> */
    private const ITEM_FIELDS = [
        'keyword',
        'title',
        'description',
        'type',
        'source',
        'keyword_id',
        'keyword_ref',
        'article_id',
        'article_ref',
    ];

    public function __construct(
        private readonly PlanningDraftIntakeService $intake,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ContentProjectIdempotencyStore $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function intake(array $payload, ?string $idempotencyKey = null): ServiceApiDraftIntakeResult
    {
        $siteId = $this->requireSiteId($payload);
        $items = $this->requireItems($payload);
        $normalized = $this->normalizeItems($items, $siteId);

        $tenantKey = 'site:'.$siteId.':actor:api';
        $idemKey = trim((string) ($idempotencyKey ?? ''));

        if ($idemKey !== '') {
            $replay = $this->idempotency->begin($tenantKey, self::ACTION, $idemKey);
            if ($replay instanceof ContentProjectActionResult) {
                $cached = $replay->metadata['service_api_draft_intake'] ?? null;
                if (is_array($cached)) {
                    return $this->resultFromCached($cached, true);
                }
            }
        }

        try {
            $draft = $this->intake->ensureSharedDraft(null, $siteId);
        } catch (Throwable $e) {
            if ($idemKey !== '') {
                $this->idempotency->complete(
                    $tenantKey,
                    self::ACTION,
                    $idemKey,
                    ContentProjectActionResult::fail(
                        ContentProjectActionCodes::FAILED,
                        $e->getMessage(),
                    ),
                );
            }
            throw new InvalidArgumentException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Shared Planning Draft could not be resolved.',
                0,
                $e,
            );
        }

        if (! $draft->isDraftPlanning() || $draft->site_id !== null) {
            throw new InvalidArgumentException('Canonical Shared Planning Draft invariant violated.');
        }

        $draftId = (int) $draft->getKey();
        $itemResults = [];
        $added = 0;
        $already = 0;
        $failed = 0;

        foreach ($normalized as $row) {
            $outcome = $this->processItem($draft, $siteId, $row);
            $itemResults[] = $outcome;
            match ($outcome->status) {
                ServiceApiDraftIntakeItemResult::STATUS_ADDED => $added++,
                ServiceApiDraftIntakeItemResult::STATUS_ALREADY_IN_DRAFT => $already++,
                default => $failed++,
            };
        }

        $result = new ServiceApiDraftIntakeResult(
            draftRef: ContentProjectPublicRef::project($draftId),
            siteRef: 'site:'.$siteId,
            submitted: count($normalized),
            added: $added,
            alreadyInDraft: $already,
            failed: $failed,
            items: $itemResults,
        );

        if ($idemKey !== '') {
            $this->idempotency->complete(
                $tenantKey,
                self::ACTION,
                $idemKey,
                ContentProjectActionResult::ok(
                    ContentProjectActionCodes::ITEMS_ADDED,
                    'Draft intake completed.',
                    $draftId,
                    metadata: [
                        'service_api_draft_intake' => $result->toArray(),
                    ],
                ),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireSiteId(array $payload): int
    {
        $unknown = array_diff(array_keys($payload), self::TOP_LEVEL_FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        if (! array_key_exists('site_id', $payload) || ! is_numeric($payload['site_id'])) {
            throw new InvalidArgumentException('site_id is required and must be a positive integer.');
        }

        $siteId = (int) $payload['site_id'];
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id is required and must be a positive integer.');
        }

        if (! $this->siteExists($siteId)) {
            throw new InvalidArgumentException('Unknown or invalid site_id.');
        }

        return $siteId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function requireItems(array $payload): array
    {
        if (! array_key_exists('items', $payload) || ! is_array($payload['items'])) {
            throw new InvalidArgumentException('items must be a non-empty array.');
        }

        if ($payload['items'] === []) {
            throw new InvalidArgumentException('items must be a non-empty array.');
        }

        if (count($payload['items']) > self::MAX_BATCH) {
            throw new InvalidArgumentException(
                'items exceeds maximum batch size of '.self::MAX_BATCH.'.'
            );
        }

        return array_values($payload['items']);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items, int $siteId): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each item must be an object.');
            }

            if (array_key_exists('site_id', $item)) {
                throw new InvalidArgumentException(
                    'Item-level site_id is not allowed (index '.$index.').'
                );
            }

            $unknown = array_diff(array_keys($item), self::ITEM_FIELDS);
            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Unknown item fields at index '.$index.': '.implode(', ', array_values($unknown))
                );
            }

            $typeWire = isset($item['type']) ? trim((string) $item['type']) : 'new';
            if ($typeWire === '') {
                $typeWire = 'new';
            }
            if (! in_array($typeWire, self::ALLOWED_TYPES, true)) {
                throw new InvalidArgumentException(
                    'Unsupported item type at index '.$index.'. Allowed: new, rewrite.'
                );
            }

            $keyword = ContentProjectItemIdentity::normalize(
                isset($item['keyword']) ? (string) $item['keyword'] : null,
            );
            $title = ContentProjectItemIdentity::normalize(
                isset($item['title']) ? (string) $item['title'] : null,
            );
            $description = ContentProjectItemIdentity::normalize(
                isset($item['description']) ? (string) $item['description'] : null,
            );

            $keywordId = $this->resolveKeywordId($item);
            $articleId = $this->resolveArticleId($item);
            $source = $this->normalizeSource($item['source'] ?? null, $index);

            $hasIdentity = ContentProjectItemIdentity::isValid($keyword, $title)
                || $keywordId > 0
                || $articleId > 0;
            if (! $hasIdentity) {
                throw new InvalidArgumentException(
                    'Item at index '.$index.' needs keyword/title and/or keyword_id|keyword_ref and/or article_id|article_ref.'
                );
            }

            $out[] = [
                'input_index' => (int) $index,
                'site_id' => $siteId,
                'type_wire' => $typeWire,
                'type' => $typeWire === 'rewrite' ? SeoProjectTask::TYPE_REWRITE : SeoProjectTask::TYPE_CREATE,
                'keyword' => $keyword,
                'title' => $title,
                'description' => $description,
                'keyword_id' => $keywordId,
                'article_id' => $articleId,
                'source' => $source,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{type: string, ref: ?string, reason: ?string}|null
     */
    private function normalizeSource(mixed $source, int $index): ?array
    {
        if ($source === null) {
            return null;
        }
        if (! is_array($source)) {
            throw new InvalidArgumentException('source must be an object at index '.$index.'.');
        }

        $allowed = ['type', 'ref', 'reason'];
        $unknown = array_diff(array_keys($source), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown source fields at index '.$index.': '.implode(', ', array_values($unknown))
            );
        }

        $type = trim((string) ($source['type'] ?? ''));
        if ($type === '' || ! in_array($type, self::ALLOWED_SOURCE_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid source.type at index '.$index.'.'
            );
        }

        $ref = isset($source['ref']) ? trim((string) $source['ref']) : '';
        $reason = isset($source['reason']) ? trim((string) $source['reason']) : '';

        return [
            'type' => $type,
            'ref' => $ref !== '' ? $ref : null,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveKeywordId(array $item): int
    {
        if (isset($item['keyword_id']) && is_numeric($item['keyword_id'])) {
            return max(0, (int) $item['keyword_id']);
        }
        $ref = trim((string) ($item['keyword_ref'] ?? ''));
        if ($ref === '') {
            return 0;
        }
        if (preg_match('/^keyword:(\d+)$/i', $ref, $m) === 1) {
            return max(0, (int) $m[1]);
        }
        if (ctype_digit($ref)) {
            return max(0, (int) $ref);
        }

        throw new InvalidArgumentException('Invalid keyword_ref. Expected keyword:{id}.');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveArticleId(array $item): int
    {
        if (isset($item['article_id']) && is_numeric($item['article_id'])) {
            return max(0, (int) $item['article_id']);
        }
        $ref = trim((string) ($item['article_ref'] ?? ''));
        if ($ref === '') {
            return 0;
        }
        if (preg_match('/^article:(\d+)$/i', $ref, $m) === 1) {
            return max(0, (int) $m[1]);
        }
        if (ctype_digit($ref)) {
            return max(0, (int) $ref);
        }

        throw new InvalidArgumentException('Invalid article_ref. Expected article:{id}.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processItem(SeoProject $draft, int $siteId, array $row): ServiceApiDraftIntakeItemResult
    {
        $index = (int) $row['input_index'];
        $source = is_array($row['source'] ?? null) ? $row['source'] : null;

        if (is_array($source) && isset($source['ref']) && is_string($source['ref']) && $source['ref'] !== '') {
            $existingTaskId = $this->findTaskIdBySourceRef(
                (int) $draft->getKey(),
                (string) $source['type'],
                $source['ref'],
            );
            if ($existingTaskId !== null) {
                return new ServiceApiDraftIntakeItemResult(
                    $index,
                    ServiceApiDraftIntakeItemResult::STATUS_ALREADY_IN_DRAFT,
                    ContentProjectPublicRef::item($existingTaskId),
                );
            }
        }

        $keywordId = (int) ($row['keyword_id'] ?? 0);
        $articleId = (int) ($row['article_id'] ?? 0);

        try {
            if ($keywordId > 0) {
                return $this->intakeExistingKeyword($draft, $siteId, $index, $keywordId, $source, $row);
            }
            if ($articleId > 0) {
                return $this->intakeExistingArticle($draft, $siteId, $index, $articleId, $source, $row);
            }

            return $this->intakeRawProposal($draft, $siteId, $index, $row, $source);
        } catch (Throwable $e) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Intake failed.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $row
     */
    private function intakeExistingKeyword(
        SeoProject $draft,
        int $siteId,
        int $index,
        int $keywordId,
        ?array $source,
        array $row,
    ): ServiceApiDraftIntakeItemResult {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: 'Unknown keyword_id.',
            );
        }

        $beforeIds = $this->draftTaskIds((int) $draft->getKey());
        $result = $this->intake->addKeywords([$keyword], [$siteId], null);
        $afterIds = $this->draftTaskIds((int) $draft->getKey());
        $newIds = array_values(array_diff($afterIds, $beforeIds));

        if ($result->isAlreadyInDraft() || ((int) ($result->summary['added'] ?? 0) === 0 && (int) ($result->summary['duplicate'] ?? 0) > 0)) {
            $taskId = $newIds[0] ?? $this->findTaskIdForKeyword($draft, $siteId, $keyword);

            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_ALREADY_IN_DRAFT,
                $taskId !== null ? ContentProjectPublicRef::item($taskId) : null,
            );
        }

        if (! $result->isSuccess() || $newIds === []) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: $result->message !== '' ? $result->message : 'Keyword intake failed.',
            );
        }

        $taskId = $newIds[0];
        $this->recordOrigin($draft, $taskId, $source, $row, null);

        return new ServiceApiDraftIntakeItemResult(
            $index,
            ServiceApiDraftIntakeItemResult::STATUS_ADDED,
            ContentProjectPublicRef::item($taskId),
        );
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $row
     */
    private function intakeExistingArticle(
        SeoProject $draft,
        int $siteId,
        int $index,
        int $articleId,
        ?array $source,
        array $row,
    ): ServiceApiDraftIntakeItemResult {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: 'Unknown article_id.',
            );
        }

        $articleSite = (int) ($article->site_id ?? 0);
        if ($articleSite > 0 && $articleSite !== $siteId) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: 'Article does not belong to the request site_id.',
            );
        }

        $beforeIds = $this->draftTaskIds((int) $draft->getKey());
        $forcedType = (string) ($row['type'] ?? SeoProjectTask::TYPE_REWRITE);
        $keywordHint = (string) ($row['keyword'] ?? '');
        $result = $this->intake->addArticles(
            [$article],
            $keywordHint !== '' ? $keywordHint : null,
            $forcedType,
            null,
        );
        $afterIds = $this->draftTaskIds((int) $draft->getKey());
        $newIds = array_values(array_diff($afterIds, $beforeIds));

        if ($result->isAlreadyInDraft() || ((int) ($result->summary['added'] ?? 0) === 0 && (int) ($result->summary['duplicate'] ?? 0) > 0)) {
            $taskId = $newIds[0] ?? $this->findTaskIdForArticle($draft, $articleId);

            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_ALREADY_IN_DRAFT,
                $taskId !== null ? ContentProjectPublicRef::item($taskId) : null,
            );
        }

        if (! $result->isSuccess() || $newIds === []) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: $result->message !== '' ? $result->message : 'Article intake failed.',
            );
        }

        $taskId = $newIds[0];
        $this->recordOrigin($draft, $taskId, $source, $row, $articleId);

        return new ServiceApiDraftIntakeItemResult(
            $index,
            ServiceApiDraftIntakeItemResult::STATUS_ADDED,
            ContentProjectPublicRef::item($taskId),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $source
     */
    private function intakeRawProposal(
        SeoProject $draft,
        int $siteId,
        int $index,
        array $row,
        ?array $source,
    ): ServiceApiDraftIntakeItemResult {
        $keyword = (string) ($row['keyword'] ?? '');
        $title = (string) ($row['title'] ?? '');
        if (! ContentProjectItemIdentity::isValid($keyword, $title)) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: ContentProjectItemIdentity::failureMessage(),
            );
        }

        $dupId = $this->findDuplicateRawTask($draft, $siteId, $keyword, $title);
        if ($dupId !== null) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_ALREADY_IN_DRAFT,
                ContentProjectPublicRef::item($dupId),
            );
        }

        $commandItem = [
            'site_id' => $siteId,
            'type' => (string) ($row['type'] ?? SeoProjectTask::TYPE_CREATE),
            'keyword' => $keyword !== '' ? $keyword : null,
            'title' => $title !== '' ? $title : null,
        ];

        $actor = new ActorContext(
            actorType: 'system',
            actorId: null,
            siteId: $siteId,
            idempotencyKey: null,
        );

        $action = $this->commandBus->dispatch(
            new AddContentProjectItemsCommand((int) $draft->getKey(), [$commandItem]),
            $actor,
        );

        if (! $action->success || $action->affectedItemIds === []) {
            return new ServiceApiDraftIntakeItemResult(
                $index,
                ServiceApiDraftIntakeItemResult::STATUS_FAILED,
                message: $action->message !== '' ? $action->message : 'Raw proposal intake failed.',
            );
        }

        $taskId = (int) $action->affectedItemIds[0];
        $this->recordOrigin($draft, $taskId, $source, $row, null);

        return new ServiceApiDraftIntakeItemResult(
            $index,
            ServiceApiDraftIntakeItemResult::STATUS_ADDED,
            ContentProjectPublicRef::item($taskId),
        );
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $row
     */
    private function recordOrigin(
        SeoProject $draft,
        int $taskId,
        ?array $source,
        array $row,
        ?int $sourceArticleId,
    ): void {
        if ($taskId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_origins')) {
            return;
        }

        $sourceType = is_array($source) ? (string) ($source['type'] ?? SeoContentProjectItemOrigin::SOURCE_MANUAL) : 'manual_api';
        if ($sourceType === 'seo_audit') {
            $sourceType = SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT;
        }

        $reasonCodes = [];
        if (is_array($source) && isset($source['reason']) && is_string($source['reason']) && $source['reason'] !== '') {
            $reasonCodes[] = $source['reason'];
        }
        if (is_array($source) && isset($source['ref']) && is_string($source['ref']) && $source['ref'] !== '') {
            $reasonCodes[] = 'source_ref:'.$source['ref'];
        }
        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') {
            $reasonCodes[] = 'planning_note:'.mb_substr($description, 0, 240);
        }

        $fingerprintSeed = is_array($source) && isset($source['ref']) && is_string($source['ref']) && $source['ref'] !== ''
            ? 'ref:'.$source['ref']
            : ContentProjectItemIdentity::normalize((string) ($row['keyword'] ?? '')).'|'.ContentProjectItemIdentity::normalize((string) ($row['title'] ?? ''));

        SeoContentProjectItemOrigin::query()->updateOrCreate(
            ['project_task_id' => $taskId],
            [
                'project_id' => (int) $draft->getKey(),
                'planner_run_id' => null,
                'source_type' => $sourceType,
                'source_article_id' => $sourceArticleId !== null && $sourceArticleId > 0 ? $sourceArticleId : null,
                'source_finding_ids' => [],
                'reason_codes' => $reasonCodes,
                'source_fingerprint' => hash('sha256', $sourceType.'|'.$fingerprintSeed),
                'created_at' => now(),
            ],
        );
    }

    private function findTaskIdBySourceRef(int $draftId, string $sourceType, string $ref): ?int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_origins')) {
            return null;
        }

        $normalizedType = $sourceType === 'seo_audit'
            ? SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT
            : $sourceType;
        $needle = 'source_ref:'.$ref;

        $origins = SeoContentProjectItemOrigin::query()
            ->where('project_id', $draftId)
            ->where('source_type', $normalizedType)
            ->orderByDesc('project_task_id')
            ->limit(200)
            ->get(['project_task_id', 'reason_codes']);

        foreach ($origins as $origin) {
            $codes = is_array($origin->reason_codes) ? $origin->reason_codes : [];
            if (in_array($needle, $codes, true)) {
                $taskId = (int) $origin->project_task_id;

                return $taskId > 0 ? $taskId : null;
            }
        }

        return null;
    }

    private function findDuplicateRawTask(SeoProject $draft, int $siteId, string $keyword, string $title): ?int
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->where('site_id', $siteId);

        if ($keyword !== '') {
            $query->where('keyword', $keyword);
        } else {
            $query->where(function ($q): void {
                $q->whereNull('keyword')->orWhere('keyword', '');
            });
        }

        if ($title !== '') {
            $query->where('title', $title);
        } else {
            $query->where(function ($q): void {
                $q->whereNull('title')->orWhere('title', '');
            });
        }

        $id = $query->orderByDesc('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function findTaskIdForKeyword(SeoProject $draft, int $siteId, Keyword $keyword): ?int
    {
        $phrase = trim((string) ($keyword->phrase ?? ''));
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->where('site_id', $siteId)
            ->orderByDesc('id');

        if ($phrase !== '') {
            $query->where('keyword', $phrase);
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function findTaskIdForArticle(SeoProject $draft, int $articleId): ?int
    {
        $id = SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->where('article_id', $articleId)
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return list<int>
     */
    private function draftTaskIds(int $draftId): array
    {
        return SeoProjectTask::query()
            ->where('project_id', $draftId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function siteExists(int $siteId): bool
    {
        if ($siteId <= 0 || ! Schema::hasTable('sites')) {
            return false;
        }

        return Site::query()->whereKey($siteId)->exists();
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function resultFromCached(array $cached, bool $replay): ServiceApiDraftIntakeResult
    {
        $items = [];
        foreach ($cached['items'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[] = new ServiceApiDraftIntakeItemResult(
                (int) ($row['input_index'] ?? 0),
                (string) ($row['status'] ?? ServiceApiDraftIntakeItemResult::STATUS_FAILED),
                isset($row['item_ref']) ? (string) $row['item_ref'] : null,
                isset($row['message']) ? (string) $row['message'] : null,
            );
        }

        return new ServiceApiDraftIntakeResult(
            draftRef: (string) ($cached['draft_ref'] ?? ''),
            siteRef: (string) ($cached['site_ref'] ?? ''),
            submitted: (int) ($cached['submitted'] ?? 0),
            added: (int) ($cached['added'] ?? 0),
            alreadyInDraft: (int) ($cached['already_in_draft'] ?? 0),
            failed: (int) ($cached['failed'] ?? 0),
            items: $items,
            idempotentReplay: $replay,
        );
    }
}
