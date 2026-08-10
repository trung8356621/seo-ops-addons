<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ExcludeKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordManualOverrideGuard;
use InvalidArgumentException;

final class ExcludeKeywordsHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ExcludeKeywordsCommand) {
            throw new InvalidArgumentException('Expected ExcludeKeywordsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $guard = class_exists(KeywordManualOverrideGuard::class)
                ? new KeywordManualOverrideGuard
                : null;

            $updated = [];
            foreach ($command->keywordRefs as $ref) {
                $id = KeywordIntelligencePublicRef::resolveKeywordIdStrict((string) $ref);
                $keyword = SeoKiKeyword::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $id)
                    ->first();
                if (! $keyword instanceof SeoKiKeyword) {
                    continue;
                }

                $keyword->is_excluded = $command->exclude;
                $sources = (array) ($keyword->field_sources ?? []);
                if ($guard !== null) {
                    $sources = $guard->touchManual($sources, 'is_excluded', $actor->actorId !== null ? 'usr_'.$actor->actorId : null);
                } else {
                    $sources['is_excluded'] = [
                        'source' => 'manual',
                        'updated_at' => gmdate('c'),
                        'actor_ref' => $actor->actorId !== null ? 'usr_'.$actor->actorId : null,
                    ];
                }
                $keyword->field_sources = $sources;
                $keyword->save();
                $updated[] = $keyword->public_ref;
            }

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::KEYWORDS_REVIEWED,
                $command->exclude ? 'Keywords excluded.' : 'Keywords restored.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'updated_keyword_refs' => $updated,
                ],
            );
        });
    }
}
