<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryAuxiliarySchema;
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
    /** @var array<string, array{hook:string,name:string}> */
    private const TYPES = [
        'core' => ['hook' => DefaultIndustryContextPromptInstaller::HOOK_KEY, 'name' => DefaultIndustryContextPromptInstaller::PROMPT_NAME],
        'discovery' => ['hook' => 'industry.discovery.generate', 'name' => 'Industry Discovery & Attention Generator'],
        'breakout' => ['hook' => 'industry.breakout.generate', 'name' => 'Industry Breakout Generator'],
        'match' => ['hook' => 'industry.match.generate', 'name' => 'Industry Match & Research Generator'],
    ];

    public function __construct(
        private readonly PromptHookBindingRunner $runner,
        private readonly ?PromptRunnerService $promptRunner = null,
        private readonly ?Closure $promptResolver = null,
    ) {}

    /** @return array<string, mixed> */
    public function generate(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): array
    {
        return $this->generateForType('core', $contextName, $language, $market, null, $notes);
    }

    /** @param array<string, mixed>|null $coreContext @return array<string, mixed> */
    public function generateForType(string $type, string $contextName, ?string $language = 'vi', ?string $market = null, ?array $coreContext = null, ?string $notes = null): array
    {
        $seed = $this->normalizedSeed($type, $contextName, $language, $market, $coreContext, $notes);
        $result = $this->runner->execute($this->resolvePrompt($type), $seed, ['locale' => $seed['language']]);

        $output = $result['value'] ?? $result['output'] ?? null;

        return $type === 'core'
            ? $this->validatedOutput($output)
            : IndustryAuxiliarySchema::validatedOutput($type, $output);
    }

    /** @return array<string, mixed> */
    public function generateFromProfile(IndustryContextProfile $profile, ?string $notes = null, ?string $market = null): array
    {
        $identity = is_array($profile->context_json['identity'] ?? null) ? $profile->context_json['identity'] : [];
        $market = trim((string) $market) ?: implode(', ', array_map('strval', (array) ($identity['market'] ?? [])));

        return $this->generate(
            (string) ($identity['context_name'] ?? $profile->name),
            (string) ($identity['language'] ?? 'en'),
            $market,
            $notes,
        );
    }

    public function compilePrompt(string $contextName, ?string $language = 'vi', ?string $market = null, ?string $notes = null): string
    {
        return $this->compilePromptForType('core', $contextName, $language, $market, null, $notes);
    }

    /** @param array<string, mixed>|null $coreContext */
    public function compilePromptForType(string $type, string $contextName, ?string $language = 'vi', ?string $market = null, ?array $coreContext = null, ?string $notes = null): string
    {
        $seed = $this->normalizedSeed($type, $contextName, $language, $market, $coreContext, $notes);
        $compiled = ($this->promptRunner ?? app(PromptRunnerService::class))->compilePrompt(
            $this->resolvePrompt($type),
            $seed,
        );
        $compiled = strtr($compiled, [
            '{{context_name}}' => $seed['context_name'],
            '{{language}}' => $seed['language'],
            '{{market}}' => $seed['market'],
            '{{core_context}}' => $seed['core_context'] ?? '',
        ]);
        $compiled = preg_replace('/\bundefined\b/i', 'unspecified', $compiled) ?? $compiled;

        return IndustryContextPromptCompiler::compileForType($compiled, $type, $seed['notes']);
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

    /** @param array<string, mixed>|null $coreContext @return array{context_name:string,language:string,market:string,core_context?:string,notes:?string} */
    private function normalizedSeed(string $type, string $contextName, ?string $language, ?string $market, ?array $coreContext, ?string $notes): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown Industry Context prompt type [{$type}].");
        }
        $contextName = trim($contextName);
        if ($contextName === '') {
            throw new InvalidArgumentException('Industry Context name is required.');
        }
        $language = trim((string) $language) ?: 'vi';
        $market = trim((string) $market);
        $notes = trim((string) $notes);
        if ($type !== 'core') {
            IndustryContextSchema::assertValid($coreContext);
            if ($market === '') {
                $market = implode(', ', array_map('strval', (array) ($coreContext['identity']['market'] ?? [])));
            }
        }
        $seed = [
            'context_name' => $contextName,
            'language' => $language,
            'market' => $market !== '' ? $market : 'unspecified / no explicit target market',
            'notes' => $notes !== '' ? $notes : null,
        ];
        if ($type !== 'core') {
            $seed['core_context'] = json_encode($coreContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $seed;
    }

    private function resolvePrompt(string $type): SeoPrompt
    {
        if ($this->promptResolver !== null) {
            $prompt = ($this->promptResolver)();
            if (! $prompt instanceof SeoPrompt) {
                throw new RuntimeException('Industry Context prompt resolver returned an invalid prompt.');
            }

            return $prompt;
        }

        $config = self::TYPES[$type];
        $query = fn (): ?SeoPrompt => SeoPrompt::query()
            ->where('hook_key', $config['hook'])
            ->where('name', $config['name'])
            ->orderBy('id')
            ->first();
        $prompt = $query();
        if ($prompt === null) {
            app(DefaultIndustryContextPromptInstaller::class)->installType($type);
            $prompt = $query();
        }

        return $prompt ?? throw new RuntimeException('Industry Context prompt could not be resolved.');
    }
}
