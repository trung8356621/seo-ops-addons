<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use JsonException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use RuntimeException;

final class IndustryContextGenerationService
{
    public function __construct(private readonly PromptHookBindingRunner $runner) {}

    /** @return array<string, mixed> */
    public function generate(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): array
    {
        $contextName = trim($contextName);
        $language = trim((string) $language) ?: 'vi';
        $market = trim((string) $market);
        app(DefaultIndustryContextPromptInstaller::class)->install();
        $prompt = SeoPrompt::query()->where('hook_key', DefaultIndustryContextPromptInstaller::HOOK_KEY)
            ->where('name', DefaultIndustryContextPromptInstaller::PROMPT_NAME)->orderBy('id')->firstOrFail();
        $result = $this->runner->execute($prompt, [
            'context_name' => $contextName, 'language' => $language, 'market' => $market, 'notes' => $notes,
        ], ['locale' => $language]);

        return $this->validatedOutput($result['value'] ?? $result['output'] ?? null);
    }

    /** @return array<string, mixed> */
    public function generateFromProfile(IndustryContextProfile $profile, ?string $notes = null): array
    {
        $identity = is_array($profile->context_json['identity'] ?? null) ? $profile->context_json['identity'] : [];
        $markets = is_array($identity['market'] ?? null) ? $identity['market'] : [];

        return $this->generate(
            (string) ($identity['context_name'] ?? $profile->name),
            (string) ($identity['language'] ?? 'en'),
            implode(', ', array_map('strval', $markets)),
            $notes,
        );
    }

    public function copyPrompt(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): string
    {
        return IndustryContextPromptCompiler::runnablePrompt($contextName, $language, $market, $notes);
    }

    /** @return array<string, mixed> */
    public function validatedOutput(mixed $output): array
    {
        if (is_string($output)) {
            try {
                $output = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Industry Context generation returned invalid JSON.', 0, $exception);
            }
        }
        IndustryContextSchema::assertValid($output);

        return $output;
    }
}
