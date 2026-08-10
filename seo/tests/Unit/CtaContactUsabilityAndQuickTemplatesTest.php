<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\CtaContactUsability;
use Omnichannel\Addons\Seo\Support\CtaQuickTemplates;
use PHPUnit\Framework\TestCase;

final class CtaContactUsabilityAndQuickTemplatesTest extends TestCase
{
    public function test_filters_unresolved_placeholders_and_empties(): void
    {
        $items = [
            ['type' => 'email', 'value' => '[email_1]', 'label' => '[email_1]'],
            ['type' => 'email', 'value' => '', 'label' => ''],
            ['type' => 'email', 'value' => null, 'label' => null],
            ['type' => 'hotline', 'value' => '0909 938 333', 'label' => '0909 938 333'],
            ['type' => 'email', 'value' => '{{ email }}', 'label' => '{{ email }}'],
            ['type' => 'address', 'value' => '{address}', 'label' => '{address}'],
        ];

        $usable = CtaContactUsability::filterUsable(array_map(
            static fn (array $row): array => [
                'type' => (string) ($row['type'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'usable' => true,
                'is_blank' => false,
            ],
            $items,
        ));

        self::assertCount(1, $usable);
        self::assertSame('hotline', $usable[0]['type']);
        self::assertSame('0909 938 333', $usable[0]['value']);
    }

    public function test_detects_placeholder_shapes(): void
    {
        self::assertTrue(CtaContactUsability::isUnresolvedPlaceholder('[email_2]'));
        self::assertTrue(CtaContactUsability::isUnresolvedPlaceholder('{{phone}}'));
        self::assertTrue(CtaContactUsability::isUnresolvedPlaceholder('{zalo}'));
        self::assertFalse(CtaContactUsability::isUnresolvedPlaceholder('contact@example.com'));
    }
}

final class CtaQuickTemplatesTest extends TestCase
{
    public function test_resolves_phone_placeholder(): void
    {
        $resolved = CtaQuickTemplates::resolve('Gọi ngay: [phone]', [
            'type' => 'hotline',
            'value' => '0909 938 333',
        ]);

        self::assertSame('Gọi ngay: 0909 938 333', $resolved);
    }

    public function test_rejects_unknown_placeholder(): void
    {
        $result = CtaQuickTemplates::validate('Gọi [unknown_value]', 'hotline');

        self::assertFalse($result['ok']);
        self::assertNotNull($result['error']);
    }

    public function test_normalize_keeps_defaults_when_empty(): void
    {
        $normalized = CtaQuickTemplates::normalize([]);

        self::assertArrayHasKey('hotline', $normalized);
        self::assertNotEmpty($normalized['hotline']['templates']);
        self::assertSame(0, $normalized['hotline']['default_index']);
    }
}
