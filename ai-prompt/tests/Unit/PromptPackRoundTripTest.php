<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class PromptPackRoundTripTest extends TestCase
{
    private PromptPackService $pack;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_unique([(string) config('database.default'), 'omi_seo_ai']) as $connection) {
            Schema::connection($connection)->dropIfExists('prompts');
            Schema::connection($connection)->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('variables')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('routing_mode')->nullable();
            $table->string('routing_profile_key')->nullable();
            $table->unsignedBigInteger('ai_connection_id')->nullable();
            $table->uuid('portable_uuid')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            });
        }

        $this->pack = new PromptPackService(
            knownHookKeys: [
                'article.content.generate',
                'product.gallery.generate',
                'article.featured_snippet.generate',
            ],
        );
        $this->actingAs($this->manager(1));
    }

    public function test_unicode_html_json_and_variables_round_trip_exactly(): void
    {
        $content = <<<'MD'
# Heading

HTML-like: <div class="x">keep</div>

```json
{"hello":"world","schema_version":"1.0"}
```

Variables: {{language}} {{article_length}}

Tiếng Việt: Đà Nẵng — “ngoặc” 🚀
MD;
        $uuid = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        SeoPrompt::query()->create([
            'user_id' => 1,
            'title' => 'Roundtrip',
            'name' => 'Roundtrip',
            'description' => 'desc',
            'markdown_content' => $content,
            'hook_key' => 'article.content.generate',
            'tools' => ImageToolType::Default->value,
            'is_active' => true,
            'routing_mode' => 'auto',
            'routing_profile_key' => 'text.longform',
            'settings' => ['portable_uuid' => $uuid],
            'portable_uuid' => $uuid,
            'variables' => [],
            'hook_settings' => [],
        ]);

        $exported = $this->pack->export(1);
        SeoPrompt::query()->withTrashed()->forceDelete();
        $plan = $this->pack->plan($exported, 1, 'update');
        $this->pack->apply($plan, 1);
        $imported = SeoPrompt::query()->where('user_id', 1)->first();
        $this->assertNotNull($imported);
        $this->assertSame($content, (string) $imported->markdown_content);
        $this->assertSame('article.content.generate', $imported->hook_key);
        $this->assertSame('text.longform', $imported->routing_profile_key);
        $this->assertNull($imported->ai_connection_id);
        $this->assertSame($uuid, $imported->settings['portable_uuid'] ?? null);
    }

    public function test_image_prompts_round_trip_tool_hook_and_profile(): void
    {
        $rows = [
            ['General image', ImageToolType::Image->value, 'article.content.generate', 'image.product', 'SPRITE {{keyword}} <img alt="x">'],
            ['Typography', ImageToolType::ImageTypography->value, 'article.featured_snippet.generate', 'image.product', 'TYPO {{language}} keep spaces  '],
            ['Gallery', ImageToolType::Image->value, 'product.gallery.generate', 'image.product', "product.gallery.generate\n{{product_name}}\nexact"],
        ];
        foreach ($rows as $i => $row) {
            [$name, $tool, $hook, $profile, $content] = $row;
            $uuid = sprintf('bbbbbbbb-2222-4222-8222-%012d', $i + 1);
            SeoPrompt::query()->create([
                'user_id' => 1,
                'title' => $name,
                'name' => $name,
                'markdown_content' => $content,
                'hook_key' => $hook,
                'tools' => $tool,
                'is_active' => true,
                'routing_mode' => 'auto',
                'routing_profile_key' => $profile,
                'settings' => ['portable_uuid' => $uuid, 'routing_family_key' => 'gemini.flash'],
                'portable_uuid' => $uuid,
            ]);
        }

        $exported = $this->pack->export(1);
        $this->assertStringNotContainsString('"id":', json_encode($exported['prompts'], JSON_THROW_ON_ERROR));
        SeoPrompt::query()->withTrashed()->forceDelete();
        $this->pack->apply($this->pack->plan($exported, 1), 1);

        foreach ($rows as $i => $row) {
            [$name, $tool, $hook, $profile, $content] = $row;
            $prompt = SeoPrompt::query()->where('name', $name)->first();
            $this->assertNotNull($prompt, $name);
            $this->assertSame($content, (string) $prompt->markdown_content);
            $this->assertSame($tool, $prompt->tools);
            $this->assertSame($hook, $prompt->hook_key);
            $this->assertSame($profile, $prompt->routing_profile_key);
        }
    }

    public function test_same_uuid_updates_name_only_conflict_copies(): void
    {
        $uuid = 'cccccccc-3333-4333-8333-cccccccccccc';
        SeoPrompt::query()->create([
            'user_id' => 1,
            'title' => 'Existing',
            'name' => 'Existing',
            'markdown_content' => 'old',
            'settings' => ['portable_uuid' => $uuid],
            'portable_uuid' => $uuid,
            'tools' => 'default',
        ]);
        SeoPrompt::query()->create([
            'user_id' => 1,
            'title' => 'Other',
            'name' => 'Name only',
            'markdown_content' => 'keep',
            'settings' => ['portable_uuid' => 'dddddddd-4444-4444-8444-dddddddddddd'],
            'portable_uuid' => 'dddddddd-4444-4444-8444-dddddddddddd',
            'tools' => 'default',
        ]);

        $payload = [
            'package_type' => 'prompt_pack',
            'schema_version' => '1.0',
            'prompts' => [
                [
                    'portable_uuid' => $uuid,
                    'name' => 'Existing',
                    'content' => 'new body',
                    'tool' => 'default',
                    'enabled' => true,
                ],
                [
                    'portable_uuid' => 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
                    'name' => 'Name only',
                    'content' => 'copy me',
                    'tool' => 'default',
                    'enabled' => true,
                ],
            ],
        ];
        $plan = $this->pack->plan($payload, 1, 'update');
        $this->assertSame('update', $plan->prompts[0]['action']);
        $this->assertSame('portable_uuid', $plan->prompts[0]['conflict']);
        $this->assertSame('copy', $plan->prompts[1]['action']);
        $this->assertSame('name', $plan->prompts[1]['conflict']);
        $this->pack->apply($plan, 1);
        $this->assertSame('new body', SeoPrompt::query()->where('portable_uuid', $uuid)->value('markdown_content'));
        $this->assertSame(3, SeoPrompt::query()->count());
        $this->assertTrue(SeoPrompt::query()->where('name', 'like', 'Name only (copy)%')->exists());
    }

    public function test_unknown_hook_imports_disabled(): void
    {
        $plan = $this->pack->plan([
            'package_type' => 'prompt_pack',
            'schema_version' => '1.0',
            'prompts' => [[
                'portable_uuid' => 'ffffffff-6666-4666-8666-ffffffffffff',
                'name' => 'Unknown hook',
                'content' => 'x',
                'hook' => 'foo.bar',
                'tool' => 'default',
                'enabled' => true,
            ]],
        ], 1);
        $this->assertFalse($plan->prompts[0]['normalized']['enabled']);
        $this->assertContains('Unknown hook: foo.bar', $plan->warnings);
        $this->assertSame('', $plan->prompts[0]['normalized']['hook']);
    }

    public function test_cross_installation_does_not_copy_database_ids(): void
    {
        $uuid = '99999999-7777-4777-8777-999999999999';
        $first = SeoPrompt::query()->create([
            'user_id' => 1,
            'title' => 'A',
            'name' => 'A',
            'markdown_content' => 'body-a',
            'settings' => ['portable_uuid' => $uuid],
            'portable_uuid' => $uuid,
            'tools' => 'default',
        ]);
        $sourceId = (int) $first->id;
        $exported = $this->pack->export(1);
        SeoPrompt::query()->withTrashed()->forceDelete();
        for ($i = 0; $i < 5; $i++) {
            SeoPrompt::query()->create([
                'user_id' => 1,
                'title' => 'pad-'.$i,
                'name' => 'pad-'.$i,
                'markdown_content' => 'pad',
                'tools' => 'default',
                'settings' => ['portable_uuid' => sprintf('12121212-1212-4212-8212-%012d', $i)],
            ]);
        }
        SeoPrompt::query()->where('name', 'like', 'pad-%')->delete();
        $this->pack->apply($this->pack->plan($exported, 1), 1);
        $imported = SeoPrompt::query()->where('name', 'A')->first();
        $this->assertNotNull($imported);
        $this->assertNotSame($sourceId, (int) $imported->id);
        $this->assertSame($uuid, $imported->settings['portable_uuid']);
        $this->assertSame('body-a', $imported->markdown_content);
    }

    public function test_content_user_cannot_import_prompts(): void
    {
        $this->actingAs($this->contentUser(9));
        $this->expectException(ConfigurationPackageException::class);
        $this->pack->apply($this->pack->plan([
            'package_type' => 'prompt_pack',
            'schema_version' => '1.0',
            'prompts' => [[
                'name' => 'Nope',
                'content' => 'x',
                'tool' => 'default',
            ]],
        ], 9), 9);
    }

    public function test_content_user_cannot_import_global_settings_page(): void
    {
        $this->actingAs($this->contentUser(9));
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());
        $this->assertFalse(SeoSettingsConfigurationTransfer::canAccess());
    }

    public function test_prompt_pack_does_not_touch_image_runtime(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(PromptPackService::class))->getFileName());
        $this->assertStringNotContainsString('ImageRoutingStrategy', $src);
        $this->assertStringNotContainsString('ImageOutputModePromptInjector', $src);
        $this->assertStringNotContainsString('generate(', $src);
    }

    private function manager(int $id): User
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = $id;

        return $user;
    }

    private function contentUser(int $id): User
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 1,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = $id;

        return $user;
    }
}
