<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpDevice;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpQueryStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpQueryNormalizationService;
use InvalidArgumentException;

final class CreateSerpQueriesHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpQueryNormalizationService $normalizer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateSerpQueriesCommand) {
            throw new InvalidArgumentException('Expected CreateSerpQueriesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $created = [];
            foreach ($command->queries as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $queryText = trim((string) ($row['query'] ?? ''));
                if ($queryText === '') {
                    continue;
                }

                $normalizedQuery = $this->normalizer->normalizeQuery($queryText);
                $providerKey = trim((string) ($row['provider_key'] ?? $command->providerKey ?? 'manual_import'));
                $language = (string) ($row['language'] ?? '');
                $country = (string) ($row['country'] ?? '');
                $location = (string) ($row['location'] ?? '');
                $device = SerpDevice::tryFrom((string) ($row['device'] ?? 'desktop')) ?? SerpDevice::Desktop;
                $searchEngine = (string) ($row['search_engine'] ?? 'google');
                $identityHash = $this->normalizer->identityHash(
                    $normalizedQuery,
                    $language,
                    $country,
                    $location,
                    $device->value,
                    $searchEngine,
                    $providerKey,
                );

                $model = new SeoSerpQuery([
                    'public_ref' => 'pending',
                    'tenant_id' => $workspace->tenant_id,
                    'site_id' => $workspace->site_id,
                    'workspace_id' => $workspace->id,
                    'keyword_id' => isset($row['keyword_ref'])
                        ? KeywordIntelligencePublicRef::resolveKeywordIdStrict((string) $row['keyword_ref'])
                        : null,
                    'cluster_id' => isset($row['cluster_ref'])
                        ? KeywordIntelligencePublicRef::resolveClusterIdStrict((string) $row['cluster_ref'])
                        : null,
                    'query' => $queryText,
                    'normalized_query' => $normalizedQuery,
                    'identity_hash' => $identityHash,
                    'language' => $language,
                    'country' => $country,
                    'location' => $location,
                    'device' => $device,
                    'search_engine' => $searchEngine,
                    'provider_key' => $providerKey,
                    'status' => SerpQueryStatus::Active,
                    'settings' => is_array($row['settings'] ?? null) ? $row['settings'] : [],
                    'created_by' => $actor->actorId,
                ]);
                $model->save();
                $model->public_ref = KeywordIntelligencePublicRef::serpQuery((int) $model->id);
                $model->save();
                $created[] = $model->public_ref;
            }

            if ($created === []) {
                return ContentProjectActionResult::fail(
                    SerpIntelligenceActionCodes::VALIDATION_FAILED,
                    'No valid queries to create.',
                );
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::QUERIES_CREATED,
                'SERP queries created.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'query_refs' => $created,
                ],
            );
        });
    }
}
