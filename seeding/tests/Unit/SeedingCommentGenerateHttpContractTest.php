<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use App\Core\Sites\SiteAccess;
use App\Models\User;
use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingCommentGenerateController;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use ReflectionClass;
use Tests\TestCase;

/**
 * Controller boundary: source_type normalization + System AI comment flow.
 */
final class SeedingCommentGenerateHttpContractTest extends TestCase
{
    /** @var callable(array<string,mixed>): \Illuminate\Http\JsonResponse */
    private $invoke;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $user = new User();
        $user->forceFill([
            'id' => 1,
            'email' => 'seeding-comment-test@example.com',
            'status' => 'active',
            'role' => User::ROLE_OWNER,
        ]);
        $this->actingAs($user);

        $textPort = new class implements AiTextExecutionPort {
            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                unset($compiledPrompt, $hookKey, $options);

                return [
                    'text' => json_encode([
                        'comments' => [
                            'Comment A rất tự nhiên',
                            'Comment B góc nhìn khác',
                            'Comment C ngắn gọn',
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'provider' => 'test',
                    'model' => 'test-model',
                ];
            }
        };

        $shared = new class extends SeedingSharedCommentPromptResolver {
            public function resolveActive(): array
            {
                $prompt = new SeoPrompt();
                $prompt->forceFill([
                    'id' => 99,
                    'markdown_content' => "HTTP contract prompt\n{{mcp_context}}",
                    'hook_key' => SeedingCommentGenerateCapabilityHandler::KEY,
                    'hook_version' => '0.1.0',
                ]);

                return [
                    'prompt' => $prompt,
                    'prompt_id' => 99,
                    'prompt_version_id' => 1,
                    'hook_key' => SeedingCommentGenerateCapabilityHandler::KEY,
                    'hook_version' => '0.1.0',
                    'body' => (string) $prompt->markdown_content,
                ];
            }
        };

        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: SeedingCommentGenerateCapabilityHandler::KEY,
            owner: 'seeding',
            handler: new SeedingCommentGenerateCapabilityHandler(
                contextResolver: new SeedingSocialContextResolver(),
                sharedPrompt: $shared,
            ),
            sideEffectFree: false,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry, $textPort),
            capabilities: $registry,
        );

        $resolver = new SeedingSocialContextResolver();
        $generator = new SeedingCommentGenerateService(
            systemAi: $client,
            contextResolver: $resolver,
            sharedPrompt: $shared,
        );
        $access = new SeedingAccess(app(SiteAccess::class), new SeedingServiceResolver());

        $this->invoke = static function (array $payload) use ($access, $generator, $resolver): \Illuminate\Http\JsonResponse {
            $request = Request::create('/api/seeding/comments/generate', 'POST', $payload);
            app()->instance('request', $request);
            $controller = new SeedingCommentGenerateController();

            return $controller($request, $access, $generator, $resolver);
        };
    }

    public function test_a_text_source_returns_exactly_three_comments(): void
    {
        $response = ($this->invoke)([
            'source_type' => 'text',
            'content' => 'Đầu tư một cái xài nguyên thời học sinh tính ra quá lời',
            'social' => 'threads',
            'quantity' => 3,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $comments = $response->getData(true)['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertCount(3, $comments);
        self::assertSame('Comment A rất tự nhiên', $comments[0]);
        Http::assertNothingSent();
    }

    public function test_b_url_preview_does_not_fetch_network(): void
    {
        $response = ($this->invoke)([
            'source_type' => 'url',
            'url' => 'https://example.com/balo-natoli',
            'preview_title' => 'Balo laptop 15.6 inch NATOLI',
            'preview_description' => 'Cặp đi học nam chống sốc',
            'social' => 'threads',
            'quantity' => 3,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $response->getData(true)['comments']);
        Http::assertNothingSent();
    }

    public function test_d_legacy_payload_normalizes(): void
    {
        $response = ($this->invoke)([
            'full_text' => 'Nội dung legacy full_text',
            'social_url' => '',
            'count' => 3,
            'platform' => 'threads',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $response->getData(true)['comments']);
    }

    public function test_e_social_platform_not_rejected_as_source_type(): void
    {
        $response = ($this->invoke)([
            'source_type' => 'threads',
            'content' => 'Nội dung khi source_type nhầm platform',
            'social' => 'threads',
            'quantity' => 3,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $response->getData(true)['comments']);
        self::assertStringNotContainsStringIgnoringCase(
            'selected source type is invalid',
            (string) $response->getContent(),
        );
    }

    public function test_manual_topic_source_type_is_not_rejected(): void
    {
        $response = ($this->invoke)([
            'source_type' => 'manual',
            'full_text' => 'Chủ đề thủ công',
            'quantity' => 3,
            'social' => 'facebook',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $response->getData(true)['comments']);
    }

    public function test_f_social_task_has_no_crawler_or_seo_prompt_dependency(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SocialCommentGenerateTask::class))->getFileName()
        );
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('SeoPrompt', $src);
        self::assertStringNotContainsString('crawl', strtolower($src));
    }
}
