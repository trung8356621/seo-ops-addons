<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntentClassifier;
use PHPUnit\Framework\TestCase;

final class KeywordIntentClassifierTest extends TestCase
{
    public function test_informational_commercial_transactional_local(): void
    {
        $c = new KeywordIntentClassifier;

        $info = $c->classifyResult('seo la gi', 'seo la gi');
        self::assertSame(KeywordSearchIntent::Informational, $info->primaryIntent);

        $commercial = $c->classifyResult('dich vu seo review', 'dich vu seo review');
        self::assertContains($commercial->primaryIntent, [
            KeywordSearchIntent::Commercial,
            KeywordSearchIntent::Mixed,
        ]);

        $tx = $c->classifyResult('bao gia seo', 'bao gia seo');
        self::assertContains($tx->primaryIntent, [
            KeywordSearchIntent::Transactional,
            KeywordSearchIntent::Commercial,
            KeywordSearchIntent::Mixed,
        ]);

        $local = $c->classifyResult('dich vu seo tphcm', 'dich vu seo tphcm');
        self::assertContains($local->primaryIntent, [
            KeywordSearchIntent::Local,
            KeywordSearchIntent::Mixed,
        ]);
    }

    public function test_ai_path_falls_back_to_rule_without_provider(): void
    {
        $c = new KeywordIntentClassifier;
        $result = $c->classifyWithOptionalAi('xyz obscure phrase', 'xyz obscure phrase', [
            'use_ai' => true,
            'provider_key' => '',
        ]);
        self::assertSame('rule', $result->source);
    }
}
