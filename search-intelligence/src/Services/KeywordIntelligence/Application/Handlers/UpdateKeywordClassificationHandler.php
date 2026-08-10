<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\UpdateKeywordClassificationCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class UpdateKeywordClassificationHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UpdateKeywordClassificationCommand) {
            throw new InvalidArgumentException('Expected UpdateKeywordClassificationCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

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

                $sources = (array) ($keyword->field_sources ?? []);
                $touch = static function (string $field) use (&$sources, $actor): void {
                    $sources[$field] = [
                        'source' => 'manual',
                        'updated_at' => gmdate('c'),
                        'actor_ref' => $actor->actorId !== null ? 'usr_'.$actor->actorId : null,
                    ];
                };

                if ($command->searchIntent !== null) {
                    $intent = KeywordSearchIntent::tryFrom($command->searchIntent);
                    if ($intent === null) {
                        throw new InvalidArgumentException('Invalid search_intent.');
                    }
                    $keyword->search_intent = $intent->value;
                    $touch('search_intent');
                }

                if ($command->funnelStage !== null) {
                    $funnel = KeywordFunnelStage::tryFrom($command->funnelStage);
                    if ($funnel === null) {
                        throw new InvalidArgumentException('Invalid funnel_stage.');
                    }
                    $keyword->funnel_stage = $funnel->value;
                    $touch('funnel_stage');
                }

                if ($command->businessValue !== null) {
                    $keyword->business_value_score = max(0, min(100, $command->businessValue));
                    $touch('business_value');
                }

                $keyword->field_sources = $sources;
                $keyword->save();
                $updated[] = $keyword->public_ref;
            }

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::KEYWORDS_REVIEWED,
                'Keyword classification updated (manual).',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'updated_keyword_refs' => $updated,
                ],
            );
        });
    }
}
