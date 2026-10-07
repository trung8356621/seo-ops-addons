<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\SystemMatchResearchSource;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchProjector;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchRegistryService;
use PHPUnit\Framework\TestCase;

final class MatchResearchRegistryServiceTest extends TestCase
{
    public function test_system_entries_cannot_be_deleted_and_identity_stable(): void
    {
        $system = new class implements SystemMatchResearchSource
        {
            public function resources(): array
            {
                return [$this->find('system.cta_blacklist')];
            }

            public function find(string $key): ?MatchResearchResource
            {
                return new MatchResearchResource(
                    key: 'system.cta_blacklist',
                    origin: MatchResearchOrigin::System,
                    kind: MatchResearchKind::RuleSet,
                    sourceLocale: 'vi',
                    label: 'CTA / Noise',
                    description: 'Noise',
                    matchMode: 'phrase',
                    payload: ['legacy_key' => 'cta_blacklist', 'terms' => ['liên hệ ngay']],
                    capabilities: new MatchResearchCapabilities(true, false, true, false),
                    editable: true,
                    deletable: false,
                );
            }
        };

        $registry = new MatchResearchRegistryService($system, null, null, null);
        $list = $registry->list(null, MatchResearchOrigin::System);
        self::assertCount(1, $list);
        self::assertFalse($list[0]->deletable);
        self::assertSame('system.cta_blacklist', $list[0]->key);
        self::assertSame('system.cta_blacklist', $registry->find('system.cta_blacklist')?->key);
        self::assertSame('system.cta_blacklist', $registry->find('cta_blacklist')?->key);
    }

    public function test_industry_entries_resolve_from_provider(): void
    {
        $provider = new class implements IndustryMatchRuleProvider
        {
            public function rulesForSite(int $siteId): array
            {
                return [];
            }

            public function rulesForKey(?string $industryContextKey): array
            {
                return [
                    'products' => [['canonical' => 'balo', 'aliases' => []]],
                    'aliases' => [],
                    'ambiguities' => [],
                ];
            }

            public function provenanceForKey(?string $industryContextKey): ?array
            {
                return ['industry_context_key' => 'bags', 'match_revision_id' => 7, 'stale' => true];
            }
        };

        $registry = new MatchResearchRegistryService(
            null,
            new IndustryMatchResearchProjector($provider),
            null,
            null,
        );
        $list = $registry->list(1, MatchResearchOrigin::Industry, 'bags');
        self::assertNotSame([], $list);
        self::assertTrue($list[0]->provenance['stale']);
        self::assertSame(7, $list[0]->provenance['match_revision_id']);
    }
}
