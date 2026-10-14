<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Routing\LocalToolRoute;
use Omnichannel\Addons\AgentRuntime\Routing\UncertainScopeInterpretation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UncertainScopeInterpretationTest extends TestCase
{
    #[Test]
    public function empty_candidates_stay_unrelated_and_existing_candidates_clarify_without_execution(): void
    {
        $interpreter = new UncertainScopeInterpretation();
        $unrelated = $interpreter->interpret(new LocalToolRoute('none', null, null, false, [], 'weighted'));
        self::assertSame('unrelated', $unrelated['kind']);

        $related = $interpreter->interpret(new LocalToolRoute(
            'none',
            null,
            null,
            false,
            [],
            'hybrid_weighted',
            null,
            null,
            null,
            false,
            [],
            ['internal' => ['candidates' => [
                ['operation' => 'seo_audit.overview', 'example' => 'Tình hình SEO Audit'],
                ['operation' => 'gsc.performance', 'example' => 'Hiệu suất GSC'],
            ]]],
        ));

        self::assertSame('clarify', $related['kind']);
        self::assertSame(['seo_audit.overview', 'gsc.performance'], $related['refs']);
        self::assertStringContainsString('Tình hình SEO Audit', $related['vi']);
        self::assertStringContainsString('Hiệu suất GSC', $related['vi']);
    }
}
