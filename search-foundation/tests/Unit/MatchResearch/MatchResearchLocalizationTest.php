<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationImporter;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocalizationPromptBuilder;
use PHPUnit\Framework\TestCase;

final class MatchResearchLocalizationTest extends TestCase
{
    public function test_prompt_contains_identity_source_and_target(): void
    {
        $resource = $this->sampleResource();
        $prompt = (new MatchResearchLocalizationPromptBuilder)->build($resource, 'en');

        self::assertStringContainsString('"resource_key": "custom.recruitment"', $prompt);
        self::assertStringContainsString('"source_locale": "vi"', $prompt);
        self::assertStringContainsString('"target_locale": "en"', $prompt);
        self::assertStringContainsString('Return JSON only', $prompt);
        self::assertStringContainsString('OEM', $prompt);
    }

    public function test_valid_localization_json_imports(): void
    {
        $resource = $this->sampleResource();
        $json = json_encode([
            'schema_version' => MatchResearchLocalizationPromptBuilder::SCHEMA_VERSION,
            'resource_key' => $resource->key,
            'origin' => $resource->origin->value,
            'kind' => $resource->kind->value,
            'source_locale' => $resource->sourceLocale,
            'target_locale' => 'en',
            'payload' => [
                'name' => 'Recruitment',
                'description' => 'Hiring intent',
                'aliases' => ['recruitment', 'hiring', 'hiring'],
                'positive_examples' => ['job openings'],
                'negative_examples' => ['product catalog'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = (new MatchResearchLocalizationImporter)->validateAndNormalize($json, $resource, 'en');
        self::assertTrue($result['ok']);
        self::assertSame(['recruitment', 'hiring'], $result['payload']['aliases']);
        self::assertSame('vi', $resource->sourceLocale);
    }

    public function test_wrong_resource_key_rejected(): void
    {
        $resource = $this->sampleResource();
        $json = json_encode([
            'schema_version' => MatchResearchLocalizationPromptBuilder::SCHEMA_VERSION,
            'resource_key' => 'custom.other',
            'origin' => 'custom',
            'kind' => 'concept',
            'source_locale' => 'vi',
            'target_locale' => 'en',
            'payload' => ['name' => 'X'],
        ], JSON_THROW_ON_ERROR);

        $result = (new MatchResearchLocalizationImporter)->validateAndNormalize($json, $resource, 'en');
        self::assertFalse($result['ok']);
        self::assertTrue(collect($result['errors'])->contains(fn (string $e): bool => str_contains($e, 'resource_key')));
    }

    public function test_wrong_locale_and_invalid_schema_rejected(): void
    {
        $resource = $this->sampleResource();
        $importer = new MatchResearchLocalizationImporter;

        $wrongLocale = json_encode([
            'schema_version' => MatchResearchLocalizationPromptBuilder::SCHEMA_VERSION,
            'resource_key' => $resource->key,
            'origin' => 'custom',
            'kind' => 'concept',
            'source_locale' => 'vi',
            'target_locale' => 'ja',
            'payload' => ['name' => 'X'],
        ], JSON_THROW_ON_ERROR);
        $badLocale = $importer->validateAndNormalize($wrongLocale, $resource, 'en');
        self::assertFalse($badLocale['ok']);

        $badSchema = $importer->validateAndNormalize('{"schema_version":"0.9","payload":{}}', $resource, 'en');
        self::assertFalse($badSchema['ok']);

        $sameLocale = $importer->validateAndNormalize('{}', $resource, 'vi');
        self::assertFalse($sameLocale['ok']);
        self::assertTrue(collect($sameLocale['errors'])->contains(fn (string $e): bool => str_contains($e, 'source locale')));
    }

    private function sampleResource(): MatchResearchResource
    {
        return new MatchResearchResource(
            key: 'custom.recruitment',
            origin: MatchResearchOrigin::Custom,
            kind: MatchResearchKind::Concept,
            sourceLocale: 'vi',
            label: 'Tuyển dụng',
            description: 'Ý định tuyển dụng',
            matchMode: null,
            payload: [
                'name' => 'Tuyển dụng',
                'description' => 'Ý định tuyển dụng',
                'aliases' => [],
                'positive_examples' => ['tuyển dụng nhân sự'],
                'negative_examples' => ['bán hàng'],
            ],
            capabilities: new MatchResearchCapabilities(false, true, true, false),
            editable: true,
            deletable: true,
            siteId: 1,
        );
    }
}
