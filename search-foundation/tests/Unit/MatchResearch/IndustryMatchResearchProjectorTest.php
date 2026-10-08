<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchProjector;
use PHPUnit\Framework\TestCase;

final class IndustryMatchResearchProjectorTest extends TestCase
{
    public function test_projects_industry_entries_with_provenance_and_ambiguity_not_taggable(): void
    {
        $provider = new class implements IndustryMatchRuleProvider
        {
            public function rulesForSite(int $siteId): array
            {
                return $this->rulesForKey('bags');
            }

            public function rulesForKey(?string $industryContextKey): array
            {
                return [
                    'products' => [['canonical' => 'balo', 'aliases' => ['ba lô'], 'match_mode' => 'phrase']],
                    'generic_cores' => [['canonical' => 'gia cong', 'aliases' => []]],
                    'ambiguities' => [['term' => 'dù', 'match_mode' => 'token', 'do_not_confuse_with' => ['ô']]],
                    'aliases' => [],
                ];
            }

            public function provenanceForKey(?string $industryContextKey): ?array
            {
                return [
                    'industry_context_key' => 'bags',
                    'match_revision_id' => 42,
                    'stale' => false,
                ];
            }

            public function statusForKey(?string $industryContextKey): string
            {
                return 'active';
            }
        };

        $resources = (new IndustryMatchResearchProjector($provider))->project('bags', 'vi');
        self::assertNotSame([], $resources);

        $byKind = [];
        foreach ($resources as $resource) {
            $byKind[$resource->kind->value][] = $resource;
            self::assertFalse($resource->deletable);
            self::assertFalse($resource->editable);
            self::assertSame('bags', $resource->provenance['industry_context_key']);
            self::assertSame(42, $resource->provenance['match_revision_id']);
            self::assertFalse($resource->provenance['stale']);
        }

        self::assertNotEmpty($byKind['concept'] ?? []);
        $ambiguity = $byKind['ambiguity'][0] ?? null;
        self::assertNotNull($ambiguity);
        self::assertSame(MatchResearchKind::Ambiguity, $ambiguity->kind);
        self::assertFalse($ambiguity->capabilities->canTag);
        self::assertTrue($ambiguity->capabilities->canMatch);

        $topic = null;
        foreach ($resources as $resource) {
            if (($resource->payload['group'] ?? '') === 'generic_cores') {
                $topic = $resource;
                break;
            }
        }
        self::assertNotNull($topic);
        self::assertFalse($topic->capabilities->canTag);
    }
}
