<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use PHPUnit\Framework\TestCase;

final class TopicPlanningRefContractTest extends TestCase
{
    public function test_encode_decode_round_trip(): void
    {
        self::assertSame('topic:75', TopicPlanningRef::encode(75));
        self::assertSame('topic:300', TopicPlanningRef::encode(300));
        self::assertSame(75, TopicPlanningRef::decode('topic:75'));
        self::assertSame(300, TopicPlanningRef::decode('topic:300'));
        self::assertTrue(TopicPlanningRef::isTopicRef('topic:75'));
    }

    public function test_rejects_invalid_and_manual_refs(): void
    {
        self::assertSame('', TopicPlanningRef::encode(0));
        self::assertNull(TopicPlanningRef::decode(''));
        self::assertNull(TopicPlanningRef::decode('manual:seed-text'));
        self::assertNull(TopicPlanningRef::decode('clu_legacy'));
        self::assertNull(TopicPlanningRef::decode('topic:'));
        self::assertNull(TopicPlanningRef::decode('topic:abc'));
        self::assertNull(TopicPlanningRef::decode('topic:-1'));
        self::assertFalse(TopicPlanningRef::isTopicRef('manual:seed'));
        self::assertFalse(TopicPlanningRef::isTopicRef('cluster_key_x'));
    }
}
