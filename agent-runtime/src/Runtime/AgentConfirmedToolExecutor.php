<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\TopicGroupArticleSource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;

final class AgentConfirmedToolExecutor
{
    private const CONNECTED_TOOLS = [
        'gsc.performance',
        'content_projects.read',
        'seo_audit.worst_articles',
    ];

    public function __construct(
        private readonly RetrievalExecutor $retrieval,
        private readonly SeoAuditAgentReadService $seoAudit,
    ) {}

    public function execute(AgentToolConfirmationProposal $proposal, int $userId): RetrievalBundle
    {
        $scope = AgentProjectScope::fromArray($proposal->scope);
        if (! $scope->isSite() || $scope->siteId === null) {
            throw new InvalidArgumentException('Confirmed Tool execution requires a site scope.');
        }

        $this->validateProposal($proposal);
        foreach ($proposal->toolCapabilities as $capability) {
            if (! in_array($capability, self::CONNECTED_TOOLS, true)) {
                throw new InvalidArgumentException("Confirmed Tool capability [{$capability}] is not connected.");
            }
        }

        $retrievalCapabilities = array_values(array_filter(
            $proposal->capabilities,
            static fn (string $capability): bool => $capability !== 'seo_audit.worst_articles',
        ));
        $modules = $retrievalCapabilities === [] ? [] : AgentCapabilityCatalog::modulesFor($retrievalCapabilities);
        $bundle = $modules === []
            ? new RetrievalBundle($scope, [])
            : $this->retrieval->execute(new RetrievalDecision(
                true,
                $proposal->intent,
                $proposal->primaryCapability,
                $retrievalCapabilities,
                $modules[0] ?? null,
                $modules,
                $proposal->parameters,
                false,
                false,
                $proposal->responseTemplate,
                $proposal->responseLanguage,
            ), $scope);

        if (! in_array('seo_audit.worst_articles', $proposal->toolCapabilities, true)) {
            return $bundle;
        }

        $limit = max(1, min(100, (int) ($proposal->parameters['limit_max'] ?? 50)));
        $context = new AgentExecutionContext(
            actorRef: 'user:'.$userId,
            actorType: 'agent',
            tenantRef: 'user:'.$userId,
            siteRef: (string) $scope->siteRef,
            requestRef: 'agent-confirmed-tool',
            resolvedSiteId: $scope->siteId,
            resolvedActorUserId: $userId,
            scopes: ['content-project:read'],
        );
        $data = $this->seoAudit->listArticles($context, [
            'low_score' => true,
            'limit' => $limit,
            'published_only' => true,
            'sort_score_asc' => true,
        ]);
        $data['draft_action'] = 'content_project.draft.intake';
        $data['draft_item_type'] = 'improve';
        $data['draft_source'] = 'seo_audit';

        return $this->appendTopicGroups(new RetrievalBundle(
            $scope,
            [...$bundle->sources, new RetrievalSource(
                'articles',
                'ok',
                'SeoAuditAgentReadService::listArticles?low_score=true&limit='.$limit,
                $data,
            )],
            $bundle->warnings,
        ), $proposal->intent);
    }

    private function appendTopicGroups(RetrievalBundle $bundle, string $query): RetrievalBundle
    {
        if (config('agent-runtime.local_tool_router.enabled') !== true) {
            return $bundle;
        }
        if ($bundle->scope->siteId === null || ! function_exists('app') || ! app()->bound(TopicGroupArticleSource::class)) {
            return $bundle;
        }
        $source = app()->make(TopicGroupArticleSource::class);
        if (! $source instanceof TopicGroupArticleSource) {
            return $bundle;
        }
        $data = $source->retrieve((int) $bundle->scope->siteId, $query);
        $sources = [];
        foreach ($bundle->sources as $existing) {
            $sources[] = $existing->name === 'articles'
                ? $this->intersectLowScoreArticles($existing, $data)
                : $existing;
        }

        return new RetrievalBundle(
            $bundle->scope,
            [...$sources, new RetrievalSource('topic_groups', (string) ($data['status'] ?? 'ok'), 'topic-group-retrieval', $data)],
            $bundle->warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $topic
     */
    private function intersectLowScoreArticles(RetrievalSource $articles, array $topic): RetrievalSource
    {
        $items = is_array($articles->data['items'] ?? null) ? $articles->data['items'] : [];
        $refs = $this->topicArticleRefs($topic);
        $status = (string) ($topic['status'] ?? 'empty');
        $allowed = array_fill_keys($refs, true);
        $matched = [];
        if ($status === 'ok') {
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($allowed[(string) ($item['article_ref'] ?? '')])) {
                    continue;
                }
                $matched[] = $item;
            }
        }
        $data = $articles->data;
        $data['items'] = $matched;
        $data['total'] = count($matched);
        $data['intersection'] = [
            'applied' => true,
            'topic_status' => $status,
            'topic_article_refs' => $refs,
        ];

        return new RetrievalSource(
            'articles',
            $status === 'ok' ? 'ok' : $status,
            $articles->request.'&topic_group_intersection=1',
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $topic
     * @return list<string>
     */
    private function topicArticleRefs(array $topic): array
    {
        $refs = [];
        foreach (is_array($topic['groups'] ?? null) ? $topic['groups'] : [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach (is_array($group['keywords'] ?? null) ? $group['keywords'] : [] as $keyword) {
                $ref = is_array($keyword['article'] ?? null) ? trim((string) ($keyword['article']['ref'] ?? '')) : '';
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    public function validateProposal(AgentToolConfirmationProposal $proposal): void
    {
        if ($proposal->capabilities === [] || $proposal->toolCapabilities === []) {
            throw new InvalidArgumentException('Frozen confirmation proposal has no Tool capabilities.');
        }
        if ($proposal->primaryCapability === '' || ! in_array($proposal->primaryCapability, $proposal->capabilities, true)) {
            throw new InvalidArgumentException('Frozen primary capability contradicts the capability list.');
        }
        foreach ($proposal->capabilities as $capability) {
            $metadata = AgentCapabilityCatalog::get($capability);
            if (($metadata['jev_selectable'] ?? false) !== true
                || ($metadata['status'] ?? null) !== 'available'
                || in_array((string) ($metadata['execution_mode'] ?? ''), ['hidden', 'internal'], true)) {
                throw new InvalidArgumentException("Frozen capability [{$capability}] is no longer available.");
            }
        }

        $expectedTools = AgentCapabilityCatalog::toolCapabilities($proposal->capabilities);
        $frozenTools = array_values(array_unique($proposal->toolCapabilities));
        sort($expectedTools);
        sort($frozenTools);
        if ($expectedTools !== $frozenTools) {
            throw new InvalidArgumentException('Frozen Tool proposal contradicts the capability registry.');
        }
    }
}
