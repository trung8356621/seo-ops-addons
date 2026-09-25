<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use Omnichannel\Addons\Seeding\Models\WebsiteShareJob;
use Omnichannel\Addons\Seeding\Services\WebsiteShareContentService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebsiteShareContentServiceTest extends TestCase
{
    public function test_three_targets_use_one_execution_and_map_by_social(): void
    {
        $client = new class implements SystemAiClient {
            public int $calls = 0;
            public ?AiExecutionRequest $request = null;

            public function execute(AiExecutionRequest $request): AiExecutionResult
            {
                $this->calls++;
                $this->request = $request;

                return new AiExecutionResult('exec-1', 'completed', 'seeding.website_share.generate', [
                    'outputs' => [
                        ['social' => 'threads', 'content' => 'Threads copy'],
                        ['social' => 'facebook', 'content' => 'Facebook copy'],
                        ['social' => 'pinterest', 'content' => 'Pinterest copy'],
                    ],
                ]);
            }

            public function getExecution(string $id): ?AiExecutionResult
            {
                return null;
            }
        };

        $job = new WebsiteShareJob(['title' => 'Article', 'article_url' => 'https://example.test/article']);
        $outputs = (new WebsiteShareContentService($client))->generate($job, ['facebook', 'pinterest', 'threads']);

        self::assertSame(1, $client->calls);
        self::assertSame(['facebook', 'pinterest', 'threads'], $client->request?->input['socials']);
        self::assertSame('Facebook copy', $outputs['facebook']);
        self::assertSame('Pinterest copy', $outputs['pinterest']);
        self::assertSame('Threads copy', $outputs['threads']);
    }

    public function test_invalid_social_output_is_rejected(): void
    {
        $client = new class implements SystemAiClient {
            public function execute(AiExecutionRequest $request): AiExecutionResult
            {
                return new AiExecutionResult('exec-2', 'completed', 'seeding.website_share.generate', [
                    'outputs' => [
                        ['social' => 'facebook', 'content' => 'One'],
                        ['social' => 'facebook', 'content' => 'Duplicate'],
                    ],
                ]);
            }

            public function getExecution(string $id): ?AiExecutionResult
            {
                return null;
            }
        };

        $this->expectException(RuntimeException::class);
        (new WebsiteShareContentService($client))->generate(new WebsiteShareJob(), ['facebook', 'threads']);
    }
}
