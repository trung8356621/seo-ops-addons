<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\MatchResearchConsumerPolicy;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchConsumerPolicyStore;
use Tests\TestCase;

final class MatchResearchConsumerPolicyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('omi_seo_ai')->dropIfExists('seo_match_consumer_policies');
        Schema::connection('omi_seo_ai')->create('seo_match_consumer_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('policy_key', 191);
            $table->json('resource_keys');
            $table->timestamps();
            $table->unique(['site_id', 'policy_key']);
        });
    }

    public function test_stores_stable_resource_keys_for_future_topic_exclusion(): void
    {
        $store = new MatchResearchConsumerPolicyStore;
        $store->put(5, MatchResearchConsumerPolicy::POLICY_TOPIC_EXCLUDE_FROM_RECLUSTER, [
            'system.cta_blacklist',
            'system.sentence_hints',
            'custom.recruitment',
            'system.cta_blacklist',
            '',
        ]);

        self::assertSame(
            ['system.cta_blacklist', 'system.sentence_hints', 'custom.recruitment'],
            $store->resourceKeys(5, MatchResearchConsumerPolicy::POLICY_TOPIC_EXCLUDE_FROM_RECLUSTER),
        );
        self::assertSame([], $store->resourceKeys(9, MatchResearchConsumerPolicy::POLICY_TOPIC_EXCLUDE_FROM_RECLUSTER));
    }
}
