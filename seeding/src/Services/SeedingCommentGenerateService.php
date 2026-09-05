<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use RuntimeException;
use Throwable;

/**
 * Stateless sample-comment generation via shared AiPrompt text providers.
 *
 * No Seeding DB writes. No SEO business imports beyond shared AI routing models.
 */
final class SeedingCommentGenerateService
{
    public function __construct(
        private readonly AiProviderResolver $aiProviders,
    ) {}

    /**
     * @return list<string>
     */
    public function generate(string $fullText, ?string $socialUrl = null, ?string $platform = null, int $count = 5): array
    {
        $count = max(1, min(12, $count));
        $fullText = trim($fullText);
        if ($fullText === '') {
            throw new RuntimeException('Thiếu nội dung gốc để gen bình luận.');
        }

        [$connection, $model] = $this->resolveActiveRoute();
        $providerKey = strtolower((string) $connection->provider);
        $provider = $this->aiProviders->resolveText($providerKey);

        $prompt = $this->buildPrompt($fullText, $socialUrl, $platform, $count);
        $result = $provider->generate(
            new AiTextRequest($prompt, (string) $model->raw_model_name, [
                'hook_key' => 'seeding.comment_generate',
                'max_output' => 2048,
            ]),
            new AiExecutionContext(
                providerKey: $providerKey,
                connectionId: (int) $connection->id,
                connection: $connection,
                metadata: ['caller' => self::class],
            ),
        );

        if (! $result->ok) {
            throw new RuntimeException($result->message !== '' ? $result->message : 'AI không tạo được bình luận.');
        }

        return $this->parseComments((string) $result->text, $count);
    }

    /**
     * @return array{0: ApiConnection, 1: SeoAiModel}
     */
    private function resolveActiveRoute(): array
    {
        $model = SeoAiModel::query()
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->whereHas('apiConnection', static function ($q): void {
                $q->where('status', 'active')->whereNotNull('api_key');
            })
            ->with('apiConnection')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();

        if (! $model instanceof SeoAiModel || ! $model->apiConnection instanceof ApiConnection) {
            throw new RuntimeException('Chưa có model AI active. Đồng bộ AI Center trước khi gen bình luận.');
        }

        return [$model->apiConnection, $model];
    }

    private function buildPrompt(string $fullText, ?string $socialUrl, ?string $platform, int $count): string
    {
        $platformLabel = trim((string) ($platform ?: 'social'));
        $urlLine = $socialUrl ? "Link bài: {$socialUrl}\n" : '';

        return <<<PROMPT
Bạn là trợ lý viết bình luận mạng xã hội (tiếng Việt).
Nhiệm vụ: tạo đúng {$count} bình luận mẫu ngắn, tự nhiên, không spam, không emoji quá nhiều.
Nền tảng: {$platformLabel}
{$urlLine}
Nội dung gốc:
\"\"\"
{$fullText}
\"\"\"

Trả về JSON array thuần (không markdown), mỗi phần tử là một chuỗi bình luận.
Ví dụ: ["Bình luận 1","Bình luận 2"]
PROMPT;
    }

    /**
     * @return list<string>
     */
    private function parseComments(string $text, int $count): array
    {
        $trimmed = trim($text);
        $json = $trimmed;
        if (preg_match('/\[[\s\S]*\]/', $trimmed, $m) === 1) {
            $json = $m[0];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (is_string($row) && trim($row) !== '') {
                    $out[] = trim($row);
                } elseif (is_array($row) && isset($row['text']) && is_string($row['text']) && trim($row['text']) !== '') {
                    $out[] = trim($row['text']);
                }
            }
        }

        if ($out === []) {
            foreach (preg_split('/\r\n|\n|\r/', $trimmed) ?: [] as $line) {
                $line = trim(preg_replace('/^[\-\*\d\.\)\s]+/', '', $line) ?? '');
                if ($line !== '' && ! str_starts_with($line, '[') && ! str_starts_with($line, '{')) {
                    $out[] = $line;
                }
            }
        }

        $out = array_values(array_unique($out));

        return array_slice($out, 0, $count);
    }
}
