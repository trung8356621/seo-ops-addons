<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SplitKeywordClusterCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterMutationService;
use InvalidArgumentException;

final class SplitKeywordClusterHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordClusterMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SplitKeywordClusterCommand) {
            throw new InvalidArgumentException('Expected SplitKeywordClusterCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $sourceId = KeywordIntelligencePublicRef::resolveClusterIdStrict($command->sourceClusterRef);
            $groups = [];
            foreach ($command->groups as $group) {
                $keywordIds = array_map(
                    static fn (string $ref): int => KeywordIntelligencePublicRef::resolveKeywordIdStrict($ref),
                    $group['keyword_refs'] ?? [],
                );
                $primaryId = null;
                if (! empty($group['primary_keyword_ref'])) {
                    $primaryId = KeywordIntelligencePublicRef::resolveKeywordIdStrict((string) $group['primary_keyword_ref']);
                }
                $groups[] = [
                    'name' => (string) ($group['name'] ?? ''),
                    'keyword_ids' => $keywordIds,
                    'primary_keyword_id' => $primaryId,
                ];
            }

            $fingerprint = $this->buildFingerprint('split_cluster', (int) $workspace->id, [
                'source' => $command->sourceClusterRef,
                'groups' => $command->groups,
            ]);

            if ($command->dryRun || $this->requiresConfirmation($actor, $command->confirmationToken)) {
                if ($command->dryRun || $command->confirmationToken === null || trim((string) $command->confirmationToken) === '') {
                    return ContentProjectActionResult::ok(
                        KeywordIntelligenceActionCodes::SPLIT_PREVIEW,
                        'Split preview ready.',
                        metadata: [
                            'confirmation_token' => $this->previewToken->issue($fingerprint),
                            'group_count' => count($groups),
                        ],
                    );
                }

                $denied = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, true);
                if ($denied !== null) {
                    return $denied;
                }
            }

            $result = $this->mutations->split($workspace, $sourceId, $groups, $command->leaveUnassigned);
            $this->consumeConfirmationToken($command->confirmationToken);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CLUSTER_CREATED,
                'Cluster split completed.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'created_cluster_refs' => array_map(
                        static fn ($c): string => (string) $c->public_ref,
                        $result['created'],
                    ),
                ],
            );
        });
    }
}
