<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryContextSchema;
use App\Models\IndustryContextProfile;
use Closure;
use InvalidArgumentException;
use JsonException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use RuntimeException;

final class IndustryContextGenerationService
{
    public function __construct(
        private readonly PromptHookBindingRunner $runner,
        private readonly ?PromptRunnerService $promptRunner = null,
        private readonly ?Closure $promptResolver = null,
    ) {}

    /** @return array<string, mixed> */
    public function generate(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): array
    {
        $seed = $this->normalizedSeed($contextName, $language, $market, $notes);
        $result = $this->runner->execute($this->resolvePrompt(), $seed, ['locale' => $seed['language']]);

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

    public function compilePrompt(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): string
    {
        $seed = $this->normalizedSeed($contextName, $language, $market, $notes);
        $compiled = ($this->promptRunner ?? app(PromptRunnerService::class))->compilePrompt(
            $this->resolvePrompt(),
            $seed,
        );

        return IndustryContextPromptCompiler::compile($compiled, $seed['notes']);
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

    /** @return array{context_name:string,language:string,market:?string,notes:?string} */
    private function normalizedSeed(string $contextName, ?string $language, ?string $market, ?string $notes): array
    {
        $contextName = trim($contextName);
        if ($contextName === '') {
            throw new InvalidArgumentException('Industry Context name is required.');
        }
        $language = trim((string) $language) ?: 'vi';
        $market = trim((string) $market);
        $notes = trim((string) $notes);

        return [
            'context_name' => $contextName,
            'language' => $language,
            'market' => $market !== '' ? $market : null,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    private function resolvePrompt(): SeoPrompt
    {
        if ($this->promptResolver !== null) {
            $prompt = ($this->promptResolver)();
            if (! $prompt instanceof SeoPrompt) {
                throw new RuntimeException('Industry Context prompt resolver returned an invalid prompt.');
            }

            return $prompt;
        }

        $query = fn (): ?SeoPrompt => SeoPrompt::query()
            ->where('hook_key', DefaultIndustryContextPromptInstaller::HOOK_KEY)
            ->where('name', DefaultIndustryContextPromptInstaller::PROMPT_NAME)
            ->orderBy('id')
            ->first();
        $prompt = $query();
        if ($prompt === null) {
            app(DefaultIndustryContextPromptInstaller::class)->install();
            $prompt = $query();
        }

        return $prompt ?? throw new RuntimeException('Industry Context prompt could not be resolved.');
    }
}
