<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInput;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputFactory;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalMapper;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingScope;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDissolveService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualOwnership;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMoveKeywordService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicRenameService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSplitService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Topic grouping boundary: business orchestration depends on the provider
 * contract, and the legacy engine stays behind that contract.
 */
final class TopicGroupingBoundaryTest extends TestCase
{
    public function test_recluster_consumes_provider_not_cluster_engine(): void
    {
        $reflection = new ReflectionClass(TopicReclusterService::class);
        $params = [];
        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $params[$param->getName()] = (string) $param->getType();
        }

        self::assertSame(TopicGroupingProvider::class, $params['grouping']);
        self::assertArrayNotHasKey('engine', $params);

        $src = (string) file_get_contents((string) $reflection->getFileName());
        self::assertStringNotContainsString('TopicClusterEngine', $src);
        self::assertStringContainsString('TopicGroupingProvider', $src);
        self::assertStringContainsString('->analyze(', $src);
        self::assertStringContainsString('$this->identity->apply', $src);
        self::assertStringContainsString('$this->discoveredIdentity->apply', $src);
        self::assertLessThan(
            (int) strpos($src, '$this->identity->apply'),
            (int) strpos($src, '->analyze('),
        );
        self::assertStringContainsString("'accept_attach' => false", $src);
        self::assertStringNotContainsString('$this->reconcile', $src);
    }

    public function test_default_binding_is_legacy_provider(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/SearchIntelligenceServiceProvider.php');
        self::assertStringContainsString('TopicGroupingProvider::class', $src);
        self::assertStringContainsString('TopicGroupingProviderMode::isSemanticHttp()', $src);
        self::assertStringContainsString('LegacyTopicGroupingProvider::class', $src);
        self::assertStringContainsString('SemanticHttpTopicGroupingProvider::class', $src);
        self::assertTrue(is_a(LegacyTopicGroupingProvider::class, TopicGroupingProvider::class, true));
        self::assertTrue(is_a(
            \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\SemanticHttpTopicGroupingProvider::class,
            TopicGroupingProvider::class,
            true,
        ));
    }

    public function test_legacy_provider_matches_engine_for_attach_and_manual_freeze(): void
    {
        $seeds = [[
            'keyword_id' => 1,
            'phrase' => 'balo laptop',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'balo laptop', 'is_seo_keyword' => true],
            ['keyword_id' => 50, 'phrase' => 'balo laptop da thật', 'is_seo_keyword' => true],
            ['keyword_id' => 60, 'phrase' => 'túi xách handmade a', 'is_seo_keyword' => true],
            ['keyword_id' => 61, 'phrase' => 'túi xách handmade b', 'is_seo_keyword' => true],
            ['keyword_id' => 62, 'phrase' => 'túi xách handmade c', 'is_seo_keyword' => true],
        ];
        $inventory = [[
            'topic_id' => 900,
            'name' => 'Manual Balo Group',
            'is_locked' => false,
            'accept_attach' => false,
            'members' => [[
                'keyword_id' => 50,
                'phrase' => 'balo laptop da thật',
                'source' => TopicKeywordSource::MANUAL,
                'is_seed' => false,
                'confidence' => null,
                'is_locked' => false,
            ]],
        ]];

        $directMetrics = [];
        $direct = $this->engine()->withRules([], [])->cluster($seeds, $eligible, [], [], $inventory, $directMetrics);
        $proposal = $this->provider()->analyze(TopicGroupingInputFactory::siteRecluster(
            7,
            $seeds,
            $eligible,
            [],
            [],
            $inventory,
        ));
        $mapped = (new TopicGroupingProposalMapper)->toReclusterClusters($proposal);

        self::assertEquals($direct, $mapped);
        self::assertEquals($directMetrics, $proposal->metadata[TopicGroupingProposal::META_ENGINE_METRICS]);
        self::assertNull($proposal->analysisRef);
        self::assertSame(LegacyTopicGroupingProvider::KEY, $proposal->metadata[TopicGroupingProposal::META_PROVIDER]);

        $manualMembers = [];
        foreach ($mapped as $cluster) {
            if ((int) ($cluster['topic_id'] ?? 0) === 900) {
                $manualMembers = array_column($cluster['members'], 'keyword_id');
            }
        }
        self::assertSame([50], $manualMembers);
        self::assertNotContains(50, $proposal->unassigned === [] ? [] : array_map(
            static fn (TopicGroupingCandidate $row): int => $row->keywordRef,
            $proposal->unassigned,
        ));
    }

    public function test_legacy_provider_preserves_seed_identity_input_for_business_resolver(): void
    {
        $seeds = [[
            'keyword_id' => 50,
            'phrase' => 'balo quà tặng',
            'source' => TopicKeywordSource::LINK_LIST,
            'is_seed' => true,
            'confidence' => 1.0,
        ]];
        $eligible = [
            ['keyword_id' => 50, 'phrase' => 'balo quà tặng'],
            ['keyword_id' => 51, 'phrase' => 'balo quà tặng giá rẻ'],
            ['keyword_id' => 3, 'phrase' => 'cách giặt áo'],
        ];
        $lockedKeywordIds = [100 => true];

        $direct = $this->engine()->withRules([], [])->cluster($seeds, $eligible, [], $lockedKeywordIds);
        $mapped = (new TopicGroupingProposalMapper)->toReclusterClusters(
            $this->provider()->analyze(TopicGroupingInputFactory::siteRecluster(
                4,
                $seeds,
                $eligible,
                [],
                $lockedKeywordIds,
                [],
            )),
        );

        self::assertEquals($direct, $mapped);
        self::assertNull($mapped[0]['topic_id']);

        $applied = (new TopicSeedIdentityResolver)->apply($mapped, [50 => 12]);
        self::assertSame(12, $applied[0]['topic_id']);
        $memberIds = array_column($applied[0]['members'], 'keyword_id');
        self::assertContains(50, $memberIds);
        self::assertContains(51, $memberIds);
        self::assertNotContains(100, $memberIds);
        self::assertNotContains(3, $memberIds);
    }

    public function test_membership_scan_matches_lexical_matcher_without_persisting(): void
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $matcher = new TopicMembershipMatcher($phrases);
        $eligible = [
            ['keyword_id' => 1, 'phrase' => 'Balo anh văn việt plus'],
            ['keyword_id' => 2, 'phrase' => 'Xưởng may balo anh văn hợp phát'],
            ['keyword_id' => 3, 'phrase' => 'túi đựng mỹ phẩm'],
        ];
        $label = 'Balo anh văn';
        $expected = [];
        foreach ($eligible as $row) {
            if ($matcher->matches($row['phrase'], $label)) {
                $expected[] = $row['keyword_id'];
            }
        }

        $proposal = $this->provider()->analyze(TopicGroupingInputFactory::topicMembershipScan(
            9,
            44,
            $label,
            $eligible,
        ));

        self::assertSame(TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN, $proposal->metadata[TopicGroupingProposal::META_SCOPE]);
        self::assertCount(1, $proposal->groups);
        self::assertSame(44, $proposal->groups[0]->existingTopicRef);
        self::assertSame($expected, array_map(
            static fn (TopicGroupingMember $member): int => $member->keywordRef,
            $proposal->groups[0]->members,
        ));
        self::assertSame([3], array_map(
            static fn (TopicGroupingCandidate $row): int => $row->keywordRef,
            $proposal->unassigned,
        ));
        self::assertSame(0.8, $proposal->groups[0]->members[0]->confidence);

        $legacy = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/Grouping/LegacyTopicGroupingProvider.php');
        self::assertStringNotContainsString('SeoTopic::', $legacy);
        self::assertStringNotContainsString('DB::', $legacy);
        self::assertStringNotContainsString('updateOrCreate', $legacy);
    }

    public function test_stub_provider_proposal_is_not_a_topic_write(): void
    {
        $proposal = new TopicGroupingProposal(
            [new TopicGroupingGroup(
                'seed:1',
                'Balo',
                [new TopicGroupingMember(1, 'balo', 1.0, [
                    TopicGroupingMember::EVIDENCE_SOURCE => TopicKeywordSource::LINK_LIST,
                    TopicGroupingMember::EVIDENCE_IS_SEED => true,
                    TopicGroupingMember::EVIDENCE_IS_LOCKED => false,
                ])],
                null,
                [TopicGroupingGroup::META_IS_LOCKED => false],
            )],
            [new TopicGroupingCandidate(9, 'other')],
            [TopicGroupingProposal::META_PROVIDER => 'stub'],
        );
        $stub = new TopicGroupingBoundaryStub($proposal);
        $input = new TopicGroupingInput(1, null, TopicGroupingScope::SITE_RECLUSTER, [
            new TopicGroupingCandidate(1, 'balo'),
            new TopicGroupingCandidate(9, 'other'),
        ]);

        $clusters = (new TopicGroupingProposalMapper)->toReclusterClusters($stub->analyze($input));

        self::assertNull($clusters[0]['topic_id']);
        self::assertSame('Balo', $clusters[0]['name']);
        self::assertTrue($clusters[0]['members'][0]['is_seed']);
        self::assertSame(12, (new TopicSeedIdentityResolver)->apply($clusters, [1 => 12])[0]['topic_id']);

        $mapper = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/Grouping/TopicGroupingProposalMapper.php');
        self::assertStringNotContainsString('SeoTopic', $mapper);
        self::assertStringNotContainsString('DB::', $mapper);
    }

    public function test_manual_operations_do_not_depend_on_grouping_provider(): void
    {
        foreach ([
            TopicMoveKeywordService::class,
            TopicSplitService::class,
            TopicRenameService::class,
            TopicManualOwnership::class,
            TopicDissolveService::class,
        ] as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('TopicGroupingProvider', $src, $class);
            self::assertStringNotContainsString('TopicClusterEngine', $src, $class);
        }
    }

    public function test_automatic_grouping_has_one_engine_entry_and_explicit_scan_path(): void
    {
        $reconcile = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipReconcileService.php');
        $manual = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php');

        self::assertStringContainsString('TopicGroupingProvider', $reconcile);
        self::assertStringContainsString('topicMembershipScan', $reconcile);
        self::assertStringNotContainsString('->matches(', $reconcile);
        self::assertStringNotContainsString('TopicClusterEngine', $reconcile);
        self::assertStringContainsString('skipped_locked', $reconcile);
        self::assertStringContainsString('skipped_seed', $reconcile);
        self::assertStringContainsString('$this->reconcile->reconcile(', $manual);
        self::assertStringNotContainsString('->analyze(', $manual);
        self::assertStringNotContainsString('TopicClusterEngine', $manual);

        $engineCallers = [];
        $root = dirname(__DIR__, 3).'/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! str_contains($src, 'TopicClusterEngine')) {
                continue;
            }
            $engineCallers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($engineCallers);
        self::assertSame([
            'Services/Topic/Grouping/LegacyTopicGroupingProvider.php',
            'Services/Topic/TopicClusterEngine.php',
        ], $engineCallers);
    }

    public function test_grouping_contract_has_no_semantic_transport_types(): void
    {
        $root = dirname(__DIR__, 3).'/src/Services/Topic/Grouping';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $banned = ['pgvector', 'FastAPI', 'embedding', 'Http::', 'Guzzle', 'PostgreSQL', 'webhook'];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            foreach ($banned as $token) {
                self::assertStringNotContainsString($token, $src, $file->getFilename().' '.$token);
            }
        }
    }

    private function engine(): TopicClusterEngine
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);

        return new TopicClusterEngine(
            new TopicMembershipMatcher($phrases),
            new KeywordNormalizer,
            $phrases,
        );
    }

    private function provider(): LegacyTopicGroupingProvider
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);

        return new LegacyTopicGroupingProvider(
            new TopicClusterEngine(
                new TopicMembershipMatcher($phrases),
                new KeywordNormalizer,
                $phrases,
            ),
            new TopicMembershipMatcher($phrases),
        );
    }
}

final class TopicGroupingBoundaryStub implements TopicGroupingProvider
{
    public function __construct(
        private readonly TopicGroupingProposal $proposal,
    ) {}

    public function analyze(TopicGroupingInput $input): TopicGroupingProposal
    {
        return $this->proposal;
    }
}
