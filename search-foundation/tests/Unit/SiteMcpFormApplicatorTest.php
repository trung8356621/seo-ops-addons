<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpFormApplicator;
use PHPUnit\Framework\TestCase;

final class SiteMcpFormApplicatorTest extends TestCase
{
    public function test_apply_maps_supported_fields_and_overwrites_existing_values(): void
    {
        $plan = (new SiteMcpFormApplicator)->plan($this->preview([
            'company_short_identity' => 'Acme Co',
            'short_description' => 'Mo ta ngan',
            'website_type' => 'manufacturer',
        ], [
            'phones' => ['0901111222'],
            'emails' => ['sales@acme.test', 'not-an-email'],
            'socials' => [
                ['network' => 'twitter', 'url' => 'https://twitter.com/acme'],
                ['network' => 'unknown', 'url' => 'https://example.test/nope'],
            ],
        ]), $this->formState());

        $prompt = $plan['state']['promptContext'];

        self::assertSame('Acme Co', $prompt['company_short_identity']);
        self::assertSame('Mo ta ngan', $prompt['short_description']);
        self::assertSame('production', $plan['state']['seo_domain_type']);
        self::assertSame(['0901111222'], array_column($prompt['phones'], 'value'));
        self::assertSame(['sales@acme.test'], array_column($prompt['emails'], 'value'));
        $social = array_values($prompt['socials'])[0];
        self::assertSame('x', $social['network']);
        self::assertSame('https://twitter.com/acme', $social['url']);
        self::assertSame([
            'company_short_identity',
            'short_description',
            'seo_domain_type',
            'phones',
            'emails',
            'socials',
        ], $plan['applied']);
    }

    public function test_empty_and_unmapped_values_do_not_overwrite_form_state(): void
    {
        $current = $this->formState();
        $plan = (new SiteMcpFormApplicator)->plan($this->preview([
            'company_short_identity' => '   ',
            'short_description' => '',
            'website_type' => 'blog',
        ], [
            'phones' => [],
            'emails' => [null, ''],
            'socials' => [['network' => 'facebook', 'url' => 'not a url']],
        ]), $current);

        self::assertSame($current['promptContext']['company_short_identity'], $plan['state']['promptContext']['company_short_identity']);
        self::assertSame($current['promptContext']['short_description'], $plan['state']['promptContext']['short_description']);
        self::assertSame($current['promptContext']['phones'], $plan['state']['promptContext']['phones']);
        self::assertSame($current['promptContext']['emails'], $plan['state']['promptContext']['emails']);
        self::assertSame($current['promptContext']['socials'], $plan['state']['promptContext']['socials']);
        self::assertSame('news', $plan['state']['seo_domain_type']);
        self::assertSame('keep-me', $plan['state']['promptContext']['cta_intro']);
        self::assertSame('keep-link', $plan['state']['promptContext']['links']['link-1']['keyword']);
        self::assertSame('secret', $plan['state']['seo_read_token']);
        self::assertSame([], $plan['applied']);
    }

    public function test_oversized_description_is_skipped_without_touching_other_fields(): void
    {
        $current = $this->formState();
        $plan = (new SiteMcpFormApplicator)->plan($this->preview([
            'company_short_identity' => 'Kept Identity',
            'short_description' => implode(' ', array_fill(0, 301, 'word')),
            'website_type' => 'news',
        ]), $current);

        self::assertSame('Kept Identity', $plan['state']['promptContext']['company_short_identity']);
        self::assertSame($current['promptContext']['short_description'], $plan['state']['promptContext']['short_description']);
        self::assertSame(['company_short_identity', 'seo_domain_type'], $plan['applied']);
    }

    public function test_missing_preview_does_not_change_form(): void
    {
        $current = $this->formState();
        $plan = (new SiteMcpFormApplicator)->plan(['has_draft' => false, 'site' => [
            'company_short_identity' => 'Nope',
        ]], $current);

        self::assertSame($current, $plan['state']);
        self::assertSame([], $plan['applied']);
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private function preview(array $site, array $contact = []): array
    {
        return [
            'has_draft' => true,
            'site' => $site,
            'contact' => $contact,
            'keyword_context' => ['main_topics' => ['Topic A']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formState(): array
    {
        return [
            'domain' => 'acme.test',
            'seo_domain_type' => 'news',
            'seo_read_token' => 'secret',
            'seo_migration_token' => 'write-secret',
            'promptContext' => [
                'company_short_identity' => 'Old identity',
                'short_description' => 'Old description',
                'cta_intro' => 'keep-me',
                'phones' => ['p1' => ['value' => '0111']],
                'emails' => ['e1' => ['value' => 'old@acme.test']],
                'socials' => ['s1' => ['network' => 'facebook', 'url' => 'https://facebook.com/old']],
                'address' => '1 Main St',
                'links' => ['link-1' => ['keyword' => 'keep-link', 'link' => 'https://acme.test/a']],
            ],
        ];
    }
}
