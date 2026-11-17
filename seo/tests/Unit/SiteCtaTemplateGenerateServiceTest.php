<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSiteCtaTemplatesPromptInstaller;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplateContextBuilder;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplateGenerateService;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplateOutputValidator;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplatePoolService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use RuntimeException;
use Tests\TestCase;

final class SiteCtaTemplateGenerateServiceTest extends TestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $default = (string) config('database.default', 'sqlite');
        Config::set('database.core_connection', $default);
        $this->createCoreTables();

        $owner = User::query()->create([
            'name' => 'CTA Gen Owner',
            'email' => 'cta-gen-owner-'.uniqid('', true).'@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'cta-gen-'.uniqid('', true).'.test',
            'status' => 'active',
        ]);
    }

    public function test_failed_generate_does_not_mutate_pool(): void
    {
        $pool = app(SiteCtaTemplatePoolService::class);
        $pool->appendBatch($this->site, [['content' => 'Keep me']]);

        $service = $this->makeService(new class implements PromptHookBindingRunner
        {
            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                throw new RuntimeException('timeout');
            }
        });

        $result = $service->propose($this->site);

        self::assertFalse($result['ok']);
        self::assertContains('generate_failed', $result['errors']);
        self::assertSame('Keep me', $pool->getPool($this->site)['templates'][0]['content']);
    }

    public function test_invalid_json_returns_errors_without_writing_pool(): void
    {
        $pool = app(SiteCtaTemplatePoolService::class);
        $pool->appendBatch($this->site, [['content' => 'Seed 1'], ['content' => 'Manual 2']]);

        $service = $this->makeService(new class implements PromptHookBindingRunner
        {
            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                return ['value' => '{bad'];
            }
        });

        $result = $service->propose($this->site);

        self::assertFalse($result['ok']);
        self::assertSame([], $result['proposals']);
        self::assertNotEmpty($result['errors']);
        self::assertSame('Manual 2', $pool->getPool($this->site)['templates'][1]['content']);
    }

    public function test_valid_proposals_list_overwrite_candidates_without_auto_write(): void
    {
        $pool = app(SiteCtaTemplatePoolService::class);
        $pool->appendBatch($this->site, [['content' => 'Manual keep']]);

        $templates = [];
        for ($i = 1; $i <= 10; $i++) {
            $templates[] = ['slot' => $i, 'intent' => 'info', 'content' => 'AI '.$i];
        }

        $service = $this->makeService(new class($templates) implements PromptHookBindingRunner
        {
            /** @param list<array{slot:int,intent:string,content:string}> $templates */
            public function __construct(private readonly array $templates) {}

            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                return ['value' => json_encode(['templates' => $this->templates], JSON_UNESCAPED_UNICODE)];
            }
        });

        $result = $service->propose($this->site);

        self::assertTrue($result['ok']);
        self::assertCount(10, $result['proposals']);
        self::assertSame([], $result['overwrite_candidates']);
        self::assertSame('append', $result['mode']);
        self::assertSame('Manual keep', $pool->getPool($this->site)['templates'][0]['content']);
    }

    public function test_hook_key_is_not_article_cta_generate(): void
    {
        self::assertSame('site.cta.templates.generate', SiteCtaTemplateGenerateService::HOOK_KEY);
        self::assertNotSame('article.cta.generate', SiteCtaTemplateGenerateService::HOOK_KEY);
        self::assertSame(DefaultSiteCtaTemplatesPromptInstaller::HOOK_KEY, SiteCtaTemplateGenerateService::HOOK_KEY);
    }

    private function makeService(PromptHookBindingRunner $runner): SiteCtaTemplateGenerateService
    {
        $contextBuilder = new class extends SiteCtaTemplateContextBuilder
        {
            public function __construct() {}

            public function build(Site $site): array
            {
                return [
                    'language' => 'Vietnamese',
                    'site_short_description' => 'Test business',
                    'site_context' => 'Domain: '.(string) $site->domain,
                    'warnings' => [],
                    'available_contact_types' => ['zalo'],
                ];
            }
        };

        return new class(
            $contextBuilder,
            new SiteCtaTemplateOutputValidator,
            app(SiteCtaTemplatePoolService::class),
            $runner,
            app(SeoCreateArticleSettingsService::class),
        ) extends SiteCtaTemplateGenerateService
        {
            protected function resolvePrompt(): SeoPrompt
            {
                $prompt = new SeoPrompt;
                $prompt->id = 1;
                $prompt->hook_key = self::HOOK_KEY;
                $prompt->markdown_content = 'Generate {{language}} {{site_context}} {{site_short_description}}';
                $prompt->is_active = true;

                return $prompt;
            }
        };
    }

    private function createCoreTables(): void
    {
        Schema::dropIfExists('site_meta');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('role')->nullable();
            $table->string('status')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->nullable();
            $table->boolean('ssl')->default(false);
            $table->boolean('h1_is_anchor')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'meta_key']);
        });
    }
}
