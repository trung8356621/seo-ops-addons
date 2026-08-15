<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusFormatter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusParser;
use PHPUnit\Framework\TestCase;

final class OperationalStatusFormatterTest extends TestCase
{
    private OperationalStatusFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        SystemDateTime::useConfig([
            'timezone' => 'Asia/Ho_Chi_Minh',
            'preset' => 'vi',
        ]);
        $this->formatter = new OperationalStatusFormatter(
            connectionLabelResolver: static fn (int $id): ?string => $id === 1 ? 'SEO DB — baloquatang.net' : null,
        );
    }

    protected function tearDown(): void
    {
        SystemDateTime::useConfig(null);
        parent::tearDown();
    }

    public function test_utc_timestamp_converts_to_application_timezone(): void
    {
        $display = $this->formatter->formatWorker('2026-08-13T07:02:03+00:00');

        self::assertFalse($display['empty']);
        self::assertSame('13/08/2026 14:02', $display['text']);
        self::assertSame('13/08/2026 14:02:03', $display['tooltip']);
    }

    public function test_success_count_one(): void
    {
        $display = $this->formatter->formatSuccess(
            '2026-08-13T03:26:04+00:00|count=1|connection_id=1',
        );

        self::assertSame('13/08/2026 10:26 · 1 tác vụ', $display['text']);
        self::assertSame('13/08/2026 10:26:04', $display['tooltip']);
        self::assertStringNotContainsString('connection_id', $display['text']);
        self::assertStringNotContainsString('count=', $display['text']);
    }

    public function test_success_count_many(): void
    {
        $display = $this->formatter->formatSuccess('2026-08-13T03:26:04+00:00|count=5');

        self::assertSame('13/08/2026 10:26 · 5 tác vụ', $display['text']);
    }

    public function test_success_resolves_connection_when_requested(): void
    {
        $display = $this->formatter->formatSuccess(
            '2026-08-13T03:26:04+00:00|count=1|connection_id=1',
            includeDomain: true,
        );

        self::assertSame('13/08/2026 10:26 · 1 tác vụ · baloquatang.net', $display['text']);
        self::assertStringNotContainsString('connection_id=1', $display['text']);
    }

    public function test_missing_connection_does_not_show_raw_id(): void
    {
        $display = $this->formatter->formatSuccess(
            '2026-08-13T03:26:04+00:00|count=1|connection_id=99',
            includeDomain: true,
        );

        self::assertSame('13/08/2026 10:26 · 1 tác vụ', $display['text']);
        self::assertStringNotContainsString('connection_id', $display['text']);
        self::assertStringNotContainsString('99', $display['text']);
    }

    public function test_failure_no_progress_omits_unknown_reason(): void
    {
        $display = $this->formatter->formatFailure(
            '2026-08-12T05:20:03+00:00|due=1 no_progress reason=unknown',
        );

        self::assertSame('12/08/2026 12:20 · 1 tác vụ đến hạn · Không có tiến triển', $display['text']);
        self::assertStringNotContainsString('unknown', $display['text']);
        self::assertStringNotContainsString('due=', $display['text']);
        self::assertStringNotContainsString('reason=', $display['text']);
    }

    public function test_unknown_reason_fallback_when_alone(): void
    {
        self::assertSame('Chưa xác định nguyên nhân', $this->formatter->reasonLabel('unknown'));
    }

    public function test_null_and_empty_use_human_empty_state(): void
    {
        self::assertTrue($this->formatter->formatSuccess(null)['empty']);
        self::assertSame('Chưa có lần chạy thành công', $this->formatter->formatSuccess(null)['text']);
        self::assertSame('Chưa có hoạt động worker', $this->formatter->formatWorker('')['text']);
        self::assertSame('Chưa có lỗi', $this->formatter->formatFailure('-')['text']);
    }

    public function test_malformed_raw_does_not_crash(): void
    {
        $display = $this->formatter->formatSuccess('unexpected/malformed raw string');

        self::assertTrue($display['empty']);
        self::assertSame('Chưa có lần chạy thành công', $display['text']);

        $parsed = OperationalStatusParser::parse('unexpected/malformed raw string');
        self::assertTrue($parsed['malformed']);
        self::assertNull($parsed['occurred_at']);
    }

    public function test_english_preset_formats_count_and_reason(): void
    {
        SystemDateTime::useConfig([
            'timezone' => 'Asia/Ho_Chi_Minh',
            'preset' => 'en',
        ]);
        $formatter = new OperationalStatusFormatter();

        $success = $formatter->formatSuccess('2026-08-13T03:26:04+00:00|count=2');
        self::assertSame('August 13, 2026 10:26 AM · 2 tasks', $success['text']);

        $failure = $formatter->formatFailure('2026-08-12T05:20:03+00:00|due=1 no_progress reason=unknown');
        self::assertStringContainsString('1 due task', $failure['text']);
        self::assertStringContainsString('No progress', $failure['text']);
        self::assertStringNotContainsString('unknown', $failure['text']);
    }

    public function test_queue_health_widget_uses_formatter_not_raw_cache_string(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Widgets\ContentProjectQueueHealthWidget::class))->getFileName(),
        );

        self::assertStringContainsString('OperationalStatusFormatter', $source);
        self::assertStringNotContainsString("\$health['last_success'] ?? '—'", $source);
        self::assertStringNotContainsString("\$health['last_failure'] ?? '—'", $source);
        self::assertStringNotContainsString("\$health['last_worker_run'] ?? '—'", $source);
    }

    public function test_parser_extracts_structured_fields(): void
    {
        $parsed = OperationalStatusParser::parse(
            '2026-08-13T03:26:04+00:00|count=1|connection_id=1',
        );

        self::assertSame(1, $parsed['count']);
        self::assertSame(1, $parsed['connection_id']);
        self::assertSame('2026-08-13T03:26:04+00:00', $parsed['occurred_at']?->toIso8601String());
    }

    public function test_technical_exception_is_humanized(): void
    {
        $display = $this->formatter->formatFailure(
            '2026-08-12T05:20:03+00:00|cURL error 28: Operation timed out after 10000 milliseconds',
        );

        self::assertStringContainsString('12/08/2026 12:20', $display['text']);
        self::assertStringContainsString('Không thể kết nối WordPress.', $display['text']);
        self::assertStringNotContainsString('cURL error', $display['text']);
    }
}
