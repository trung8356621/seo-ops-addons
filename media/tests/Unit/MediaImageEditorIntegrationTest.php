<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Media\Filament\Pages\MediaImageEditor;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoMediaImageEditorResolverService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Tests\TestCase;

final class MediaImageEditorIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SeoConnectionContext::applyUrlDefaults(str_repeat('a', 32));

        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_media_meta');
        $schema->dropIfExists('seo_media');
        $schema->create('seo_media', function (Blueprint $table): void {
            $table->id();
            $table->string('filename');
            $table->string('slug');
            $table->string('path');
            $table->string('url');
            $table->string('source')->default('clipboard');
            $table->timestamps();
        });
        $schema->create('seo_media_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'meta_key']);
        });
    }

    public function test_editor_is_hidden_from_navigation_but_route_url_remains_available(): void
    {
        self::assertFalse(MediaImageEditor::shouldRegisterNavigation());

        $url = MediaImageEditor::urlForMedia(123, 'splitter');
        self::assertStringContainsString('/media-image-editor', $url);
        self::assertStringContainsString('media=123', $url);
        self::assertStringContainsString('tab=splitter', $url);
        self::assertSame($url, route('filament.seo.pages.media-image-editor', [
            'connection_hash' => str_repeat('a', 32),
            'media' => 123,
            'tab' => 'splitter',
        ]));
    }

    public function test_existing_local_media_resolves_to_the_editor_route_with_media_id(): void
    {
        $media = SeoMedia::query()->create([
            'site_id' => 77,
            'filename' => 'local.jpg',
            'slug' => 'local',
            'path' => 'uploads/seo_media/local.jpg',
            'url' => '/storage/uploads/seo_media/local.jpg',
            'source' => 'upload',
        ]);
        $site = new Site(['domain' => 'local.test', 'status' => 'active']);
        $site->id = 77;

        $resolved = app(SeoMediaImageEditorResolverService::class)->resolve($site, [
            'kind' => 'local',
            'seo_media_id' => $media->id,
        ]);

        self::assertSame((int) $media->id, $resolved['seo_media_id']);
        self::assertSame(MediaImageEditor::urlForMedia((int) $media->id), $resolved['editor_url']);
        self::assertStringContainsString('media='.$media->id, $resolved['editor_url']);
    }
}
