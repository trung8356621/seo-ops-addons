<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Support\TypographyComplexity;
use Omnichannel\Addons\Content\Support\TypographyScoringConfig;
use Omnichannel\Addons\AiPrompt\Support\VisionValidationModelRouter;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;

/**
 * Vision validation cho typography — một request mỗi candidate.
 */
final class TypographyValidationService
{
    private const HTTP_TIMEOUT_SECONDS = 90;

    public function __construct(
        private readonly TypographyScoringConfig $scoringConfig,
        private readonly VisionValidationModelRouter $visionModelRouter = new VisionValidationModelRouter(),
    ) {}

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $visibleTextBlocks
     * @return array{
     *     score: float,
     *     passed: bool,
     *     detected_blocks: array<int, array<string, mixed>>,
     *     missing_blocks: array<int, string>,
     *     mismatched_blocks: array<int, string>,
     *     extra_text: array<int, string>,
     *     validation_model: string,
     *     raw_response: array<string, mixed>|null,
     *     readability_confidence: float,
     * }
     */
    public function validateCandidate(
        ApiConnection $connection,
        string $imageAbsolutePath,
        array $visibleTextBlocks,
        TypographyComplexity $complexity,
        float $minimumScore,
        ?string $preferredModel = null,
    ): array {
        if (! is_file($imageAbsolutePath)) {
            throw new PromptRunException('Candidate image không tồn tại để validate.');
        }

        $binary = file_get_contents($imageAbsolutePath);
        if ($binary === false || $binary === '') {
            throw new PromptRunException('Không đọc được candidate image.');
        }

        $mime = $this->guessMime($imageAbsolutePath);
        $models = $this->visionModelRouter->modelsToTry($preferredModel);
        if ($models === []) {
            throw new PromptRunException('Không có model Vision validation đủ điều kiện (Gemini >= 3 + image_input + text).');
        }

        $prompt = $this->buildVisionPrompt($visibleTextBlocks, $complexity);
        $lastError = null;
        $response = null;
        $model = $models[0];

        foreach ($models as $candidateModel) {
            try {
                $response = $this->requestVision(
                    connection: $connection,
                    model: $candidateModel,
                    prompt: $prompt,
                    mimeType: $mime,
                    base64: base64_encode($binary),
                );
                $model = $candidateModel;
                break;
            } catch (PromptRunException $exception) {
                $lastError = $exception;
                logger()->warning('Vision validation model failed, try next', [
                    'validation_model' => $candidateModel,
                    'error' => $exception->getMessage(),
                ]);

                if (! \Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy::isProviderUnavailableError($exception->getMessage())
                    && ! str_contains(strtolower($exception->getMessage()), '404')
                    && ! str_contains(strtolower($exception->getMessage()), 'not found')
                ) {
                    throw $exception;
                }
            }
        }

        if ($response === null) {
            throw $lastError ?? new PromptRunException('Vision validation thất bại.');
        }

        $parsed = $this->parseVisionResponse($response);
        $detectedBlocks = is_array($parsed['detected_blocks'] ?? null) ? $parsed['detected_blocks'] : [];
        $extraText = is_array($parsed['extra_text'] ?? null) ? array_map('strval', $parsed['extra_text']) : [];

        [$missing, $mismatched] = $this->diffBlocks($visibleTextBlocks, $detectedBlocks);
        $readability = (float) ($parsed['readability_confidence'] ?? 0.85);

        $score = $this->scoringConfig->computeScore(
            expectedBlocks: $visibleTextBlocks,
            detectedBlocks: $detectedBlocks,
            missingBlockIds: $missing,
            mismatchedBlockIds: $mismatched,
            extraText: $extraText,
            readabilityConfidence: $readability,
        );

        return [
            'score' => $score,
            'passed' => $score >= $minimumScore,
            'detected_blocks' => $detectedBlocks,
            'missing_blocks' => $missing,
            'mismatched_blocks' => $mismatched,
            'extra_text' => $extraText,
            'validation_model' => $model,
            'raw_response' => $parsed,
            'readability_confidence' => $readability,
        ];
    }

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $visibleTextBlocks
     */
    private function buildVisionPrompt(array $visibleTextBlocks, TypographyComplexity $complexity): string
    {
        $expectedJson = json_encode($visibleTextBlocks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $language = $complexity->language ?? 'unknown';

        return <<<PROMPT
Analyze this image and extract ONLY visible text rendered in the image.
Return strict JSON with keys:
- detected_blocks: array of {id, text, type}
- extra_text: array of strings for invented/extra text not in expected list
- readability_confidence: float 0..1

Expected visible text blocks (language={$language}):
{$expectedJson}

Rules:
- Keep Vietnamese diacritics exact.
- Do not paraphrase expected text.
- Mark missing expected blocks by omitting them from detected_blocks.
PROMPT;
    }

  /**
   * @return array<string, mixed>
   */
    private function requestVision(
        ApiConnection $connection,
        string $model,
        string $prompt,
        string $mimeType,
        string $base64,
    ): array {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model),
        );

        $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->withHeaders([
                'x-goog-api-key' => (string) $connection->api_key,
            ])
            ->post($url, [
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => $base64,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new PromptRunException('Vision validation lỗi: '.mb_substr((string) $message, 0, 500));
        }

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function parseVisionResponse(array $response): array
    {
        $text = '';
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && filled($part['text'] ?? null)) {
                    $text .= (string) $part['text'];
                }
            }
        }

        $text = trim($text);
        if ($text === '') {
            return [
                'detected_blocks' => [],
                'extra_text' => [],
                'readability_confidence' => 0.5,
            ];
        }

        if (preg_match('/\{[\s\S]*\}/u', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'detected_blocks' => [],
            'extra_text' => [$text],
            'readability_confidence' => 0.4,
        ];
    }

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $expected
     * @param  list<array<string, mixed>>  $detected
     * @return array{0: list<string>, 1: list<string>}
     */
    private function diffBlocks(array $expected, array $detected): array
    {
        $detectedById = [];
        $detectedTexts = [];

        foreach ($detected as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            if ($id !== '') {
                $detectedById[$id] = $text;
            }
            if ($text !== '') {
                $detectedTexts[] = $this->normalizeComparable($text);
            }
        }

        $missing = [];
        $mismatched = [];

        foreach ($expected as $block) {
            $id = (string) ($block['id'] ?? '');
            $expectedText = $this->normalizeComparable((string) ($block['text'] ?? ''));
            if ($expectedText === '') {
                continue;
            }

            $actual = $id !== '' ? ($detectedById[$id] ?? null) : null;
            if ($actual === null) {
                $found = in_array($expectedText, $detectedTexts, true);
                if (! $found && (bool) ($block['required'] ?? true)) {
                    $missing[] = $id !== '' ? $id : $expectedText;
                }

                continue;
            }

            if ($this->normalizeComparable($actual) !== $expectedText) {
                $mismatched[] = $id;
            }
        }

        return [$missing, $mismatched];
    }

    private function normalizeComparable(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_strtolower($text);
    }

    private function guessMime(string $absolutePath): string
    {
        $lower = strtolower($absolutePath);

        return match (true) {
            str_ends_with($lower, '.jpg'), str_ends_with($lower, '.jpeg') => 'image/jpeg',
            str_ends_with($lower, '.webp') => 'image/webp',
            default => 'image/png',
        };
    }
}
