<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeSelectedKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordWorkspaceAnalysisService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AnalyzeSelectedKeywordsHandler extends AbstractKeywordIntelligenceHandler
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
        if (! $command instanceof AnalyzeSelectedKeywordsCommand) {
            throw new InvalidArgumentException('Expected AnalyzeSelectedKeywordsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            if ($command->keywordRefs === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                    'keyword_refs required.',
                );
            }

            $options = array_merge($command->options, [
                'keyword_refs' => $command->keywordRefs,
                'idempotency_key' => $command->idempotencyKey,
                'preserve_manual_overrides' => true,
            ]);

            try {
                $result = $this->analysis->analyze($workspace, $options);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'analysis_already_processing')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::ANALYSIS_ALREADY_PROCESSING,
                        $e->getMessage(),
                    );
                }

                throw $e;
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::FAILED,
                    $e->getMessage(),
                );
            }

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::ANALYZED,
                'Selected keywords analysis completed.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'operation_ref' => $result['operation_ref'] ?? null,
                    'summary' => $result['summary'] ?? [],
                ],
            );
        });
    }
}
