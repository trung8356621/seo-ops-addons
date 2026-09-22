<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsDuplicateFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsResultParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class DiscoverNewTopicsPr3ContractTest extends TestCase
{
    public function test_tabs_exist_and_existing_flow_unchanged(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('audit_notes_tab_existing', $blade);
        self::assertStringContainsString('audit_notes_tab_new', $blade);
        self::assertStringContainsString("setAuditNotesTab('existing')", $blade);
        self::assertStringContainsString("setAuditNotesTab('new')", $blade);
        self::assertStringContainsString('data-discover-new-topics', $blade);
        self::assertStringContainsString('audit_notes_heading', $blade);
        self::assertStringContainsString('loadAuditNoteSuggestions', $blade);
        self::assertStringContainsString('AuditNoteClusterSuggestionQuery', (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithAuditNotes::class))->getFileName(),
        ));
    }

    public function test_discover_service_uses_gateway_and_prompt_registry(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('PromptHookCallerBridge', $src);
        self::assertStringContainsString('DefaultDiscoverNewTopicsPromptInstaller::HOOK_KEY', $src);
        self::assertStringContainsString('landscape->forSite', $src);
        self::assertStringNotContainsString('TopicListQuery', $src);
        self::assertStringNotContainsString('SeoTopic::query()', $src);
        self::assertStringNotContainsString('Keyword::query()', $src);
        self::assertStringNotContainsString('createTopic', $src);
        self::assertStringNotContainsString('SeoArticle', $src);
        self::assertSame('seo_audit.discover_new_topics', DiscoverNewTopicsService::HOOK_KEY);
        self::assertSame(DefaultDiscoverNewTopicsPromptInstaller::HOOK_KEY, DiscoverNewTopicsService::HOOK_KEY);
        self::assertTrue(class_exists(KeywordLandscapeGateway::class));
    }

    public function test_parser_accepts_valid_and_rejects_invalid(): void
    {
        $parser = new DiscoverNewTopicsResultParser;
        $ok = $parser->parse([
            'topics' => [
                [
                    'candidate_key' => 'generated-1',
                    'name' => 'Balo du lịch cho trẻ em',
                    'target_dna_count' => 10,
                    'dna' => ['độ tuổi', 'dung tích', 'độ tuổi', '  '],
                ],
            ],
        ]);
        self::assertCount(1, $ok['accepted']);
        self::assertSame('generated-1', $ok['accepted'][0]['candidate_key']);
        self::assertSame(10, $ok['accepted'][0]['target_dna_count']);
        self::assertSame(['độ tuổi', 'dung tích'], $ok['accepted'][0]['dna']);

        $emptyName = $parser->parse(['topics' => [['candidate_key' => 'x', 'name' => '  ', 'target_dna_count' => 3, 'dna' => []]]]);
        self::assertSame([], $emptyName['accepted']);
        self::assertSame('empty_name', $emptyName['rejected'][0]['reason']);

        $badTarget = $parser->parse(['topics' => [['candidate_key' => 'x', 'name' => 'Ok', 'target_dna_count' => 0, 'dna' => []]]]);
        self::assertSame('invalid_target_dna_count', $badTarget['rejected'][0]['reason']);
    }

    public function test_generated_note_item_has_candidate_key_not_topic_id(): void
    {
        $item = AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
            'candidate_key' => 'generated-9',
            'name' => 'Xưởng may túi nhựa',
            'target_dna_count' => 5,
            'dna' => ['PVC', 'TPU'],
        ]);
        self::assertNotNull($item);
        self::assertSame(AuditNoteDnaNormalizer::SOURCE_TYPE_GENERATED, $item['source_type']);
        self::assertSame('generated:generated-9', $item['cluster_ref']);
        self::assertSame('generated-9', $item['candidate_key']);
        self::assertArrayNotHasKey('topic_id', $item);
        self::assertTrue(AuditNoteDnaNormalizer::isGenerated($item));
        self::assertFalse(AuditNoteDnaNormalizer::isManualSeed($item));
    }

    public function test_duplicate_filter_rejects_existing_normalized_name(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsDuplicateFilter::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('normalizeKey', $src);
        self::assertStringContainsString('duplicate_existing_topic', $src);
        self::assertStringNotContainsString('vector', strtolower($src));
    }

    public function test_livewire_trait_preserves_selected_on_error_and_no_db_write(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName(),
        );
        self::assertStringContainsString('previousSelected', $src);
        self::assertStringContainsString('DiscoverNewTopicsService', $src);
        self::assertStringContainsString('newTopicSelectedItems', $src);
        self::assertStringNotContainsString('SeoTopic::', $src);
        self::assertStringNotContainsString('create([', $src);
        self::assertStringNotContainsString('GenerateNewContentSuggestions', $src);
    }

    public function test_prompt_installer_and_hook_json_registered(): void
    {
        self::assertSame('seo_audit.discover_new_topics', DefaultDiscoverNewTopicsPromptInstaller::HOOK_KEY);
        self::assertSame('0.1.0', DefaultDiscoverNewTopicsPromptInstaller::HOOK_VERSION);
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/seo_audit.discover_new_topics@0.1.0.json';
        self::assertFileExists($path);
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame('seo_audit.discover_new_topics', $spec['key']);
        self::assertArrayHasKey('landscape_json', $spec['input_schema']);
        self::assertStringContainsString('landscape_json', (string) $spec['canonical_default']['markdown']);
        self::assertStringContainsString('candidate_key', (string) $spec['canonical_default']['markdown']);

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString('InstallDefaultDiscoverNewTopicsPromptCommand::class', $provider);
        self::assertFileExists(
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_09_22_100000_install_default_discover_new_topics_prompt_binding.php',
        );
    }

    public function test_audit_notes_suggestion_query_still_uses_gateway_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
    }
}
