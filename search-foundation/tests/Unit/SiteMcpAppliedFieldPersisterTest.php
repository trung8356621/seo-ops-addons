<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpAppliedFieldPersister;
use PHPUnit\Framework\TestCase;

final class SiteMcpAppliedFieldPersisterTest extends TestCase
{
    public function test_write_plan_patches_only_applied_fields_and_keeps_stored_siblings(): void
    {
        $plan = (new SiteMcpAppliedFieldPersister)->writePlan(
            ['company_short_identity', 'seo_domain_type'],
            [
                'seo_domain_type' => 'production',
                'seo_read_token' => 'dirty-token',
                'promptContext' => [
                    'company_short_identity' => 'Acme Co',
                    'short_description' => 'unsaved description',
                    'cta_intro' => 'unsaved intro',
                    'links' => [['keyword' => 'dirty', 'link' => 'https://dirty.test']],
                ],
            ],
            $this->storedPrompt(),
            'news',
        );

        self::assertSame('Acme Co', $plan['prompt']['company_short_identity']);
        self::assertSame('Stored description', $plan['prompt']['short_description']);
        self::assertSame('stored intro', $plan['prompt']['cta_intro']);
        self::assertSame('keep-link', $plan['prompt']['links'][0]['keyword']);
        self::assertSame('production', $plan['domain_type']);
        self::assertArrayNotHasKey('seo_read_token', $plan['prompt']);
        self::assertSame(2, $plan['count']);
    }

    public function test_identical_values_do_not_request_a_write(): void
    {
        $plan = (new SiteMcpAppliedFieldPersister)->writePlan(
            ['company_short_identity', 'phones', 'seo_domain_type'],
            [
                'seo_domain_type' => 'news',
                'promptContext' => [
                    'company_short_identity' => 'Stored identity',
                    'phones' => ['uuid' => ['value' => '0901']],
                ],
            ],
            $this->storedPrompt(),
            'news',
        );

        self::assertNull($plan['prompt']);
        self::assertNull($plan['domain_type']);
        self::assertSame(3, $plan['count']);
    }

    public function test_invalid_email_rejects_the_whole_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SiteMcpAppliedFieldPersister)->writePlan(
            ['company_short_identity', 'emails'],
            [
                'promptContext' => [
                    'company_short_identity' => 'Acme Co',
                    'emails' => ['e1' => ['value' => 'not-an-email']],
                ],
            ],
            $this->storedPrompt(),
            'news',
        );
    }

    public function test_invalid_website_type_rejects_the_whole_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SiteMcpAppliedFieldPersister)->writePlan(
            ['short_description', 'seo_domain_type'],
            [
                'seo_domain_type' => 'blog',
                'promptContext' => ['short_description' => 'Mo ta hop le'],
            ],
            $this->storedPrompt(),
            'news',
        );
    }

    public function test_persister_does_not_sync_wordpress_or_link_catalog(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(SiteMcpAppliedFieldPersister::class))->getFileName());

        self::assertStringNotContainsString('syncManualLinksFromSettings', $source);
        self::assertStringNotContainsString('WordPress', $source);
        self::assertStringNotContainsString('SiteSync', $source);
        self::assertStringNotContainsString('persistSeoMetaFormData', $source);
        self::assertStringContainsString('saveForSite', $source);
    }

    public function test_empty_applied_set_does_not_build_a_write(): void
    {
        $plan = (new SiteMcpAppliedFieldPersister)->writePlan(
            [],
            [
                'seo_domain_type' => 'production',
                'promptContext' => ['company_short_identity' => 'Acme Co'],
            ],
            $this->storedPrompt(),
            'news',
        );

        self::assertNull($plan['prompt']);
        self::assertNull($plan['domain_type']);
        self::assertSame(0, $plan['count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedPrompt(): array
    {
        return [
            'tone' => 'legacy',
            'company_short_identity' => 'Stored identity',
            'short_description' => 'Stored description',
            'cta_intro' => 'stored intro',
            'phones' => [['value' => '0901']],
            'emails' => [['value' => 'old@acme.test']],
            'socials' => [['network' => 'facebook', 'url' => 'https://facebook.com/old']],
            'address' => '1 Main St',
            'cta' => [['type' => 'working_hours', 'value' => '8-17']],
            'links' => [['keyword' => 'keep-link', 'link' => 'https://acme.test/a']],
        ];
    }
}
