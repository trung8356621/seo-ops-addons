<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordWorkspaceAnalysisService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AnalyzeKeywordWorkspaceHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordWorkspaceAnalysisService $analysis,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AnalyzeKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected AnalyzeKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $options = array_merge($command->options, [
                'strategy' => $command->clusteringStrategy,
                'keyword_refs' => $command->keywordRefs,
                'idempotency_key' => $command->idempotencyKey,
                'preserve_manual_overrides' => $command->options['preserve_manual_overrides'] ?? true,
            ]);

            try {
                $result = $this->analysis->analyze($workspace, $options);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'analysis_already_processing')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::ANALYSIS_ALREADY_PROCESSING,
                        $e->getMessage(),
                        metadata: ['workspace_ref' => $workspace->public_ref],
                    );
                }

                throw $e;
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::FAILED,
                    'Analysis failed: '.$e->getMessage(),
                    metadata: ['workspace_ref' => $workspace->public_ref],
                );
            }

            $code = (string) ($result['result_code'] ?? KeywordIntelligenceActionCodes::ANALYZED);
            if (($result['status'] ?? '') === 'partially_completed') {
                $code = KeywordIntelligenceActionCodes::ANALYSIS_PARTIAL;
            }

            return ContentProjectActionResult::ok(
                $code,
                'Workspace analysis accepted/completed.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'operation_ref' => $result['operation_ref'] ?? null,
                    'summary' => $result['summary'] ?? [],
                    'status' => $result['status'] ?? null,
                ],
            );
        });
    }
}
