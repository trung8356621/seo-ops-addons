<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\IndustryMatchResearchKeyDeriver;
use PHPUnit\Framework\TestCase;

final class IndustryMatchResearchKeyDeriverTest extends TestCase
{
    public function test_keys_are_stable_and_locale_source_based_not_translated_text(): void
    {
        $deriver = new IndustryMatchResearchKeyDeriver;
        $a = $deriver->forEntity('products', 'balo', 'vi');
        $b = $deriver->forEntity('products', 'balo', 'vi');
        $enLabelWouldChange = $deriver->forEntity('products', 'balo', 'vi');

        self::assertSame($a, $b);
        self::assertSame($a, $enLabelWouldChange);
        self::assertStringStartsWith('industry.products.', $a);
        self::assertNotSame(
            $deriver->forEntity('products', 'balo', 'vi'),
            $deriver->forEntity('products', 'balo', 'en'),
        );
    }

    public function test_ambiguity_keys_do_not_use_display_translation_as_identity(): void
    {
        $deriver = new IndustryMatchResearchKeyDeriver;
        $key = $deriver->forAmbiguity('dù', 'vi');
        self::assertStringStartsWith('industry.ambiguities.', $key);
        self::assertSame($key, $deriver->forAmbiguity('dù', 'vi'));
    }
}
