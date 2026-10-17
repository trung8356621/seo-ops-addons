<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupDatasetCanonical;
use Tests\TestCase;

final class KeywordGroupDatasetHashTest extends TestCase
{
    public function test_php_hash_matches_python_golden_vectors(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/keyword_group_dataset_hash_vectors.json';
        $cases = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(6, $cases);

        $hashes = [];
        foreach ($cases as $case) {
            $actual = KeywordGroupDatasetCanonical::datasetHash(
                (string) $case['scope_ref'],
                $case['language'],
                $case['items'],
            );
            self::assertSame($case['dataset_hash'], $actual, $case['name']);
            foreach ($case['items'] as $item) {
                self::assertSame(
                    $item['content_hash'],
                    KeywordGroupDatasetCanonical::contentHash((string) $item['text']),
                    $case['name'].':'.$item['ref'],
                );
            }
            $hashes[$case['name']] = $actual;
        }

        self::assertNotSame($hashes['two-keywords'], $hashes['evidence-sort']);
        self::assertNotSame($hashes['two-keywords'], $hashes['null-language']);
        self::assertNotSame($hashes['two-keywords'], $hashes['metadata']);
        self::assertNotSame($hashes['whitespace-nfc'], $hashes['vietnamese']);
    }
}
