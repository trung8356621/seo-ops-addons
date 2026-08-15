<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class AiKeywordDiscoveryService
{
    private const KEYWORD_COUNT = 12;

    public function discover(
        string $seedKeyword,
        string $searchIntent,
        string $targetRegion,
        string $siteMcpContext = '',
        ?int $limit = null,
    ): array {
        $seedKeyword = Keyword::decodePhrase($seedKeyword);
        if ($seedKeyword === '') {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.discovery_seed_required'));
        }

        $count = $limit !== null ? max(1, min(50, $limit)) : self::KEYWORD_COUNT;

        $model = $this->resolveGeminiModel();
        $connection = $model->apiConnection;
        if (! $connection instanceof ApiConnection) {
            throw new PromptRunException(__('seo-content-ai::filament.keyword.discovery_no_gemini'));
        }

        $prompt = $this->buildPrompt($seedKeyword, $searchIntent, $targetRegion, $siteMcpContext, $count);
        $raw = $this->callGemini($connection, $model, $prompt);
        $parsed = $this->parseSuggestions($raw, $count);

        if ($parsed === []) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.keyword.discovery_empty_response'));
        }

        return $parsed;
    }

    /**
     * @return list<array{
     *     id: string,
     *     keyword: string,
     *     intent: string,
     *     difficulty: string,
     *     title_idea: string,
     *     relevancy_reason: string,
     * }>
     */
    private function parseSuggestions(string $raw, int $limit = self::KEYWORD_COUNT): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $matches)) {
            $raw = trim($matches[1]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '[');
            $end = strrpos($raw, ']');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            return [];
        }

        $items = array_is_list($decoded) ? $decoded : ($decoded['keywords'] ?? $decoded['suggestions'] ?? []);
        if (! is_array($items)) {
            return [];
        }

        $suggestions = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $keyword = trim((string) ($item['keyword'] ?? $item['phrase'] ?? $item['suggested_keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }

            $suggestions[] = [
                'id' => (string) Str::uuid(),
                'keyword' => $keyword,
                'intent' => $this->normalizeIntent((string) ($item['intent'] ?? $item['search_intent'] ?? 'informational')),
                'difficulty' => $this->normalizeDifficulty((string) ($item['difficulty'] ?? $item['competition'] ?? 'medium')),
                'title_idea' => trim((string) ($item['title_idea'] ?? $item['title'] ?? $item['suggested_title'] ?? '')),
                'relevancy_reason' => trim((string) ($item['relevancy_reason'] ?? $item['reason'] ?? $item['relevance'] ?? '')),
            ];
        }

        return array_slice($suggestions, 0, $limit);
    }

    private function buildPrompt(
        string $seedKeyword,
        string $searchIntent,
        string $targetRegion,
        string $siteMcpContext = '',
        int $count = self::KEYWORD_COUNT,
    ): string {
        $intentLabel = match ($searchIntent) {
            'informational' => 'Informational',
            'commercial' => 'Commercial Investigation',
            'transactional' => 'Transactional',
            default => 'Mixed (Informational, Commercial, Transactional)',
        };

        $regionLabel = match ($targetRegion) {
            'vietnam' => 'Vietnam (vi-VN)',
            'global' => 'Global / English-first',
            'us' => 'United States',
            'uk' => 'United Kingdom',
            'sea' => 'Southeast Asia',
            default => $targetRegion,
        };

        $countLabel = (string) $count;
        $siteBlock = trim($siteMcpContext);
        $siteSection = $siteBlock !== ''
            ? "Compact keyword landscape (use this instead of any full keyword list):\n{$siteBlock}\n\n"
            : '';

        return <<<PROMPT
{$siteSection}Seed keyword: "{$seedKeyword}"
Preferred search intent focus: {$intentLabel}
Target region / market: {$regionLabel}

Act as a senior SEO strategist. Suggest exactly {$countLabel} NEW long-tail keyword opportunities.

Rules:
- Generate only new SEO opportunities for weak/missing topics or intents.
- Do not generate paraphrases of existing canonical keywords.
- Do not expand saturated clusters.
- Avoid full sentences, descriptive marketing copy, brand slogans, and URL/domain strings.
- Avoid near-duplicates of existing_canonicals / exclude_patterns in the landscape context.

Return ONLY a valid JSON array (no markdown prose). Each object MUST use these keys:
- keyword (string)
- intent (informational|commercial|transactional)
- difficulty (easy|medium|hard) — AI-estimated competition, not numeric KD
- title_idea (string) — compelling SEO article title in the target market language
- relevancy_reason (string) — 1-2 concise sentences explaining why this keyword fits a gap, not a paraphrase
PROMPT;
    }

    private function callGemini(ApiConnection $connection, SeoAiModel $model, string $prompt): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode((string) $model->raw_model_name),
        );

        $response = Http::timeout(180)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
                'system_instruction' => [
                    'parts' => [[
                        'text' => 'You are an SEO keyword research assistant. Always respond with valid JSON only.',
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.65,
                    'maxOutputTokens' => 8192,
                ],
            ]);

        if (! $response->successful()) {
            throw new PromptRunException(
                __('seo-content-ai::filament.keyword.discovery_gemini_error', [
                    'message' => mb_substr((string) (
                        $response->json('error.message') ?? $response->body()
                    ), 0, 500),
                ]),
            );
        }

        $answer = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if (trim($answer) === '') {
            throw new PromptRunException(__('seo-content-ai::filament.keyword.discovery_empty_response'));
        }

        return trim($answer);
    }

    private function resolveGeminiModel(): SeoAiModel
    {
        $userId = (int) (auth()->id() ?? 0);
        $ownerId = (int) (SeoAccessControl::accountOwnerId() ?? 0);
        $allowedUserIds = array_values(array_unique(array_filter([$userId, $ownerId])));

        $model = SeoAiModel::query()
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->whereIn('category', [AiModelCategory::GEMINI_FLASH, AiModelCategory::GEMINI_PRO])
            ->whereHas('apiConnection', static function ($query) use ($allowedUserIds): void {
                $query->where('status', 'active')
                    ->where('provider', 'gemini')
                    ->where(function ($scope) use ($allowedUserIds): void {
                        $scope->where('is_global', true);
                        if ($allowedUserIds !== []) {
                            $scope->orWhereIn('user_id', $allowedUserIds);
                        }
                    });
            })
            ->with('apiConnection')
            ->orderByRaw('FIELD(category, ?, ?)', [AiModelCategory::GEMINI_FLASH, AiModelCategory::GEMINI_PRO])
            ->orderByDesc('priority')
            ->first();

        if (! $model instanceof SeoAiModel) {
            throw new PromptRunException(__('seo-content-ai::filament.keyword.discovery_no_gemini'));
        }

        return $model;
    }

    private function normalizeIntent(string $intent): string
    {
        $intent = strtolower(trim($intent));

        return match (true) {
            str_contains($intent, 'transaction') => 'transactional',
            str_contains($intent, 'commercial') => 'commercial',
            default => 'informational',
        };
    }

    private function normalizeDifficulty(string $difficulty): string
    {
        $difficulty = strtolower(trim($difficulty));

        return match (true) {
            str_contains($difficulty, 'easy') || str_contains($difficulty, 'low') => 'easy',
            str_contains($difficulty, 'hard') || str_contains($difficulty, 'high') => 'hard',
            default => 'medium',
        };
    }
}
