<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Discover modal + prompt 0.2.0 context + New Topics 2-col grid contracts.
 */
final class DiscoverNewTopicsModalAndPromptV02ContractTest extends TestCase
{
    public function test_prompt_0_2_0_is_immutable_current_version(): void
    {
        self::assertSame('0.2.0', DefaultDiscoverNewTopicsPromptInstaller::HOOK_VERSION);
        self::assertSame('0.2.0', DiscoverNewTopicsService::HOOK_VERSION);

        $v01 = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/seo_audit.discover_new_topics@0.1.0.json';
        $v02 = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/seo_audit.discover_new_topics@0.2.0.json';
        self::assertFileExists($v01);
        self::assertFileExists($v02);

        $old = json_decode((string) file_get_contents($v01), true);
        $new = json_decode((string) file_get_contents($v02), true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('0.1.0', $old['version']);
        self::assertSame('0.2.0', $new['version']);
        self::assertStringContainsString('Use ONLY the supplied landscape_json', (string) ($old['canonical_default']['markdown'] ?? ''));
        self::assertStringNotContainsString('Use ONLY the supplied landscape_json', (string) ($new['canonical_default']['markdown'] ?? ''));
        self::assertArrayHasKey('company_short_identity', $new['input_schema']);
        self::assertArrayHasKey('short_description', $new['input_schema']);
        self::assertArrayHasKey('discovery_guidance', $new['input_schema']);
        self::assertArrayHasKey('avoid_topics', $new['input_schema']);
        self::assertStringContainsString('Company Short Identity', (string) ($new['canonical_default']['markdown'] ?? ''));
        self::assertStringContainsString('avoid_topics', (string) ($new['canonical_default']['markdown'] ?? ''));
    }

    public function test_service_passes_company_guidance_and_avoid_topics(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );
        self::assertStringContainsString('SiteDomainPromptContextService', $src);
        self::assertStringContainsString('company_short_identity', $src);
        self::assertStringContainsString('short_description', $src);
        self::assertStringContainsString('discovery_guidance', $src);
        self::assertStringContainsString('avoid_topics', $src);
        self::assertStringContainsString('encodeAvoidTopics', $src);
        self::assertStringContainsString('normalizeDiscoveryGuidance', $src);

        $method = new ReflectionMethod(DiscoverNewTopicsService::class, 'discover');
        $params = array_map(static fn ($p) => $p->getName(), $method->getParameters());
        self::assertContains('discoveryGuidance', $params);
        self::assertContains('avoidTopics', $params);

        self::assertSame('[]', DiscoverNewTopicsService::encodeAvoidTopics([]));
        self::assertSame('["Alpha","Beta"]', DiscoverNewTopicsService::encodeAvoidTopics(['Alpha', ' alpha ', 'Beta', '']));
        self::assertSame('', DiscoverNewTopicsService::normalizeDiscoveryGuidance("  \n  "));
        self::assertSame('Ưu tiên B2B', DiscoverNewTopicsService::normalizeDiscoveryGuidance("  Ưu tiên B2B  "));
    }

    public function test_modal_and_two_column_grid_contracts(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-audit-notes.blade.php'
        );
        self::assertStringContainsString('data-discover-new-topics-modal="1"', $blade);
        self::assertStringContainsString('data-discover-guidance="1"', $blade);
        self::assertStringContainsString('data-discover-open-modal="1"', $blade);
        self::assertStringContainsString('cpDiscoverNewTopicsModal', $blade);
        self::assertStringContainsString('new_topics_guidance_label', $blade);
        self::assertStringContainsString('new_topics_modal_title', $blade);
        self::assertStringContainsString('cp-new-topics-grid', $blade);
        self::assertStringContainsString('data-new-topics-grid="1"', $blade);
        self::assertStringContainsString('data-new-topics-selected="1"', $blade);
        self::assertStringNotContainsString('data-new-topics-candidates="1"', $blade);
        self::assertStringNotContainsString('toggleNewTopicCandidate', $blade);
        // Modal must not embed generated topic editor list.
        $modalStart = strpos($blade, 'data-discover-new-topics-modal="1"');
        self::assertNotFalse($modalStart);
        $modalChunk = substr($blade, $modalStart, 2500);
        self::assertStringNotContainsString('data-new-topics-grid', $modalChunk);
        self::assertStringNotContainsString('updateNewTopicName', $modalChunk);

        $styles = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-ops-styles.blade.php'
        );
        self::assertStringContainsString('.cp-new-topics-grid', $styles);
        self::assertStringContainsString('minmax(0, 1fr) minmax(0, 1fr)', $styles);
        self::assertStringContainsString('@media (min-width: 1024px)', $styles);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName()
        );
        self::assertStringContainsString('discoveryGuidance', $trait);
        self::assertStringContainsString('currentAvoidTopicNames', $trait);
        self::assertStringContainsString("'discovery_guidance'", $trait);
        self::assertStringContainsString('new_topics_regenerate_confirm', $blade);
        self::assertStringContainsString('window.confirm', $blade);
    }
}
