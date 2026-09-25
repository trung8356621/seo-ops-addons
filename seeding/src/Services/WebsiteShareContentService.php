<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use Omnichannel\Addons\Seeding\Models\WebsiteShareJob;
use Omnichannel\Addons\Seeding\System\WebsiteShareGenerateCapabilityHandler;
use RuntimeException;

/** Generates all pending Website Share target contents with one routed AI execution. */
final class WebsiteShareContentService
{
    public function __construct(private readonly ?SystemAiClient $systemAi = null) {}

    /** @param list<string> $socials @return array<string, string> */
    public function generate(WebsiteShareJob $job, array $socials): array
    {
        $socials = array_values(array_unique(array_filter(array_map('strval', $socials))));
        if ($socials === []) {
            throw new RuntimeException('Không có social cần gen');
        }
        $client = $this->systemAi;
        if (! $client instanceof SystemAiClient && function_exists('app') && app()->bound(SystemAiClient::class)) {
            $client = app(SystemAiClient::class);
        }
        if (! $client instanceof SystemAiClient) {
            throw new RuntimeException('System AI is unavailable for Website Share.');
        }

        $result = $client->execute(new AiExecutionRequest(
            capability: WebsiteShareGenerateCapabilityHandler::KEY,
            input: [
                'title' => $job->title,
                'article_url' => $job->article_url,
                'domain' => $job->domain,
                'socials' => $socials,
            ],
            context: ['allow_domain_side_effects' => true, 'stage' => 'website_share_generate'],
            requirements: ['structured_output' => true],
            correlation: ['stage' => 'website_share_generate', 'socials' => $socials],
        ));
        if ($result->status === 'failed') {
            throw new RuntimeException(trim((string) ($result->errorMessage ?? 'Website Share gen thất bại')));
        }

        $output = is_array($result->output) ? $result->output : [];
        $rows = is_array($output['outputs'] ?? null) ? $output['outputs'] : [];
        $requested = array_fill_keys($socials, true);
        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Website Share AI output không hợp lệ');
            }
            $social = trim((string) ($row['social'] ?? ''));
            $content = trim((string) ($row['content'] ?? ''));
            if ($social === '' || $content === '' || ! isset($requested[$social]) || isset($mapped[$social])) {
                throw new RuntimeException('Website Share AI output thiếu, trùng hoặc sai social');
            }
            $mapped[$social] = $content;
        }
        if (count($mapped) !== count($requested)) {
            throw new RuntimeException('Website Share AI output thiếu social');
        }
        foreach ($requested as $social => $_) {
            if (! isset($mapped[$social])) {
                throw new RuntimeException('Website Share AI output thiếu social');
            }
        }

        return $mapped;
    }
}
