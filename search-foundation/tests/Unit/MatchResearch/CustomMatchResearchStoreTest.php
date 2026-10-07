<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\MatchResearch;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\CustomMatchResearchStore;
use Omnichannel\Addons\SearchFoundation\Services\MatchResearch\MatchResearchLocaleOverlayStore;
use Tests\TestCase;

final class CustomMatchResearchStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_custom_concept_crud_and_site_scoping(): void
    {
        $store = new CustomMatchResearchStore;
        $a = $store->create(10, [
            'name' => 'Recruitment',
            'description' => 'Hiring',
            'source_locale' => 'vi',
            'positive_examples' => ['tuyển dụng'],
            'negative_examples' => ['bán hàng'],
        ]);
        self::assertSame('custom.recruitment', $a->key);
        self::assertTrue($a->deletable);
        self::assertFalse($a->capabilities->canMatch);
        self::assertTrue($a->capabilities->canTag);

        $store->create(11, [
            'name' => 'Recruitment',
            'source_locale' => 'vi',
        ]);

        self::assertCount(1, $store->listForSite(10));
        self::assertCount(1, $store->listForSite(11));
        self::assertNull($store->find('custom.recruitment', 99));

        $updated = $store->update('custom.recruitment', 10, [
            'name' => 'Tuyển dụng',
            'positive_examples' => ['hr'],
        ]);
        self::assertSame('Tuyển dụng', $updated->label);
        self::assertSame('vi', $updated->sourceLocale);

        $store->delete('custom.recruitment', 10);
        self::assertSame([], $store->listForSite(10));
        self::assertCount(1, $store->listForSite(11));
    }

    public function test_system_key_cannot_be_deleted_via_custom_store(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CustomMatchResearchStore)->delete('system.cta_blacklist', 10);
    }

    public function test_locale_overlay_isolation_does_not_mutate_source(): void
    {
        $store = new CustomMatchResearchStore;
        $resource = $store->create(10, [
            'name' => 'Recruitment',
            'source_locale' => 'vi',
            'positive_examples' => ['tuyển dụng'],
        ]);

        $overlays = new MatchResearchLocaleOverlayStore;
        $overlays->put($resource->key, 'en', [
            'name' => 'Recruitment',
            'aliases' => ['hiring'],
        ], 10);

        $loaded = $overlays->overlaysFor($resource->key, 10);
        self::assertArrayHasKey('en', $loaded);
        self::assertArrayNotHasKey('vi', $loaded);

        $fresh = $store->find($resource->key, 10);
        self::assertSame('vi', $fresh?->sourceLocale);
        self::assertSame(['tuyển dụng'], $fresh?->payload['positive_examples']);
    }

    private function createTables(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_match_research_locale_overlays');
        Schema::connection('omi_seo_ai')->dropIfExists('seo_match_research_custom_concepts');

        Schema::connection('omi_seo_ai')->create('seo_match_research_custom_concepts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('resource_key', 191);
            $table->string('source_locale', 16);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('positive_examples')->nullable();
            $table->json('negative_examples')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'resource_key']);
        });

        Schema::connection('omi_seo_ai')->create('seo_match_research_locale_overlays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(0)->index();
            $table->string('resource_key', 191);
            $table->string('locale', 16);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['site_id', 'resource_key', 'locale']);
        });
    }
}
