<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityContentProjectConverter;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class CreateContentProjectFromGscOpportunitiesHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscOpportunityContentProjectConverter $converter,
        private readonly ContentProjectCommandBus $bus,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateContentProjectFromGscOpportunitiesCommand) {
            throw new InvalidArgumentException('Expected CreateContentProjectFromGscOpportunitiesCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);

            $opportunities = $command->opportunityRefs === []
                ? SeoGscOpportunity::query()->where('property_id', $property->id)->limit(100)->get()->all()
                : array_map(fn (string $ref): SeoGscOpportunity => $this->resolveOpportunity($ref, $property), $command->opportunityRefs);

            $preview = $this->converter->preview($property, $opportunities, $command->projectAttributes);
            $requiresConfirmation = $this->requiresConfirmation($actor, $command->confirmationToken);

            $fingerprint = $this->buildFingerprint('gsc_intelligence.create_content_project', (int) $property->id, [
                'opportunity_refs' => $command->opportunityRefs,
                'project_attributes' => $command->projectAttributes,
            ]);

            $confirmationFailure = $this->assertConfirmationToken(
                $command->confirmationToken,
                $fingerprint,
                $requiresConfirmation,
            );

            if ($confirmationFailure !== null) {
                if ($command->confirmationToken === null || trim($command->confirmationToken) === '') {
                    $token = $this->previewToken->issue($fingerprint);

                    return ContentProjectActionResult::fail(
                        GscIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                        'Confirmation required.',
                        metadata: ['preview' => $preview, 'confirmation_token' => $token],
                    );
                }

                return $confirmationFailure;
            }

            $result = $this->converter->convert(
                $property,
                $opportunities,
                $actor,
                $this->bus,
                $command->projectAttributes,
                $command->idempotencyKey,
            );

            $this->consumeConfirmationToken($command->confirmationToken);

            return $result;
        });
    }
}
