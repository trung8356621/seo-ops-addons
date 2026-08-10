<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Seo\Http\Middleware\SeoAuthenticate;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class SeoAuthenticateConnectionHashTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoConnectionContext::reset();
        parent::tearDown();
    }

    public function test_resolve_hash_from_path(): void
    {
        $hash = str_repeat('a', 32);
        $request = Request::create('/seo/'.$hash.'/content-projects', 'GET');

        self::assertSame($hash, SeoConnectionContext::resolveHashFromRequest($request));
    }

    public function test_resolve_hash_from_referer_for_livewire(): void
    {
        $hash = str_repeat('b', 40);
        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('referer', 'https://example.test/seo/'.$hash.'/articles');

        self::assertSame($hash, SeoConnectionContext::resolveHashFromRequest($request));
    }

    public function test_seo_authenticate_middleware_exists(): void
    {
        self::assertTrue(class_exists(SeoAuthenticate::class));
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Http/Middleware/SeoAuthenticate.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString("route('filament.seo.auth.login'", $source);
        self::assertStringContainsString('connection_hash', $source);
        self::assertStringContainsString("url('/seo')", $source);
    }
}
