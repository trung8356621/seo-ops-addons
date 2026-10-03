<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentTestArticleCatalogService;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestExecutionService;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AgentTestRepairBehaviorTest extends TestCase
{
    public function test_raw_context_keeps_input_and_merges_canonical_site_variables(): void
    {
        Schema::dropIfExists('site_meta');
        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        $site = new Site(['domain' => 'mayhopphat.com']);
        $site->id = 7;
        $site->setRelation('metas', new Collection());
        $context = new TaskTestContext(null, false, null, [
            'input' => 'Viết lại đoạn này',
            'user_brief' => 'Viết lại đoạn này',
        ], 'Raw input');
        $siteContext = SiteDomainPromptContextService::withTestPayload([
            'short_description' => 'Máy phát điện',
            'cta_intro' => 'Liên hệ tư vấn',
        ]);

        $resolved = AgentTestInputResolver::mergeRawSiteContext($context, $site, $siteContext);

        self::assertSame('Viết lại đoạn này', $resolved->variables['input']);
        self::assertSame('mayhopphat.com', $resolved->variables['site_domain']);
        self::assertSame('Máy phát điện', $resolved->variables['site_short_description']);
        self::assertArrayHasKey('site_cta', $resolved->variables);
        self::assertSame(7, $resolved->siteId);
    }

    public function test_media_normalization_ignores_prose_url_but_accepts_semantic_artifact(): void
    {
        $service = (new ReflectionClass(AgentTestExecutionService::class))->newInstanceWithoutConstructor();
        $promptMedia = new ReflectionMethod($service, 'canonicalPromptMedia');
        $mediaFrom = new ReflectionMethod($service, 'mediaFrom');

        self::assertSame([], $promptMedia->invoke($service, 'Read https://example.com for details', 'image'));
        self::assertSame([], $mediaFrom->invoke($service, ['output' => 'Read https://example.com'], 'image'));
        self::assertSame(
            [['type' => 'image', 'url' => 'https://cdn.example.com/generated/123']],
            $mediaFrom->invoke($service, ['artifacts' => [['type' => 'image', 'url' => 'https://cdn.example.com/generated/123']]], 'image'),
        );
    }

    public function test_article_catalog_is_owner_and_site_scoped(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('articles');
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id');
            $table->string('title');
            $table->softDeletes();
        });
        $db = DB::connection('omi_seo_ai')->table('articles');
        $db->insert([
            ['id' => 1, 'user_id' => 10, 'site_id' => 7, 'title' => 'Alpha article'],
            ['id' => 2, 'user_id' => 10, 'site_id' => 8, 'title' => 'Alpha other site'],
            ['id' => 3, 'user_id' => 11, 'site_id' => 7, 'title' => 'Alpha other owner'],
        ]);

        $rows = (new AgentTestArticleCatalogService())->search(10, 7, 'Alpha');

        self::assertSame([['id' => 1, 'title' => 'Alpha article']], $rows);
    }
}
