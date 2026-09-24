<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Copy UX: append-link placement + robust clipboard write contracts.
 */
final class SeedingCopyClipboardContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/resources/js/seeding/'.$relative);
    }

    public function test_write_clipboard_has_clipboard_api_and_execcommand_fallback(): void
    {
        $copy = $this->js('services/copyComment.js');
        $clipboard = $this->js('services/clipboardWrite.js');
        self::assertStringContainsString("from './clipboardWrite'", $copy);
        self::assertStringContainsString('export async function writeClipboard', $clipboard);
        self::assertStringContainsString('export function copyTextViaExecCommand', $clipboard);
        self::assertStringContainsString('navigator.clipboard', $clipboard);
        self::assertStringContainsString('writeText', $clipboard);
        self::assertStringContainsString("doc.execCommand('copy')", $clipboard);
        self::assertStringContainsString("method: 'clipboard'", $clipboard);
        self::assertStringContainsString("method: 'execCommand'", $clipboard);
        self::assertStringContainsString('ok: true', $clipboard);
        self::assertStringContainsString('ok: false', $clipboard);
        self::assertStringContainsString('createElement(\'textarea\')', $clipboard);
    }

    public function test_append_link_toggle_lives_with_generated_outputs_not_gen_controls(): void
    {
        $panel = $this->js('components/ShareGeneratePanel.jsx');

        self::assertStringContainsString('data-section="seed-outputs"', $panel);
        self::assertStringContainsString('data-copy-append-toggle', $panel);
        self::assertStringContainsString('Append link khi Copy', $panel);
        self::assertStringContainsString('Comment đã Gen', $panel);

        // Toggle must only render inside outputs section (after Gen button).
        $genBtnPos = strpos($panel, "{generating ? 'Đang Gen…' : 'Gen comment'}");
        $togglePos = strpos($panel, 'data-copy-append-toggle');
        self::assertNotFalse($genBtnPos);
        self::assertNotFalse($togglePos);
        self::assertGreaterThan($genBtnPos, $togglePos);

        // Must not sit in gen controls before the Gen button.
        $beforeGen = substr($panel, 0, (int) $genBtnPos);
        self::assertStringNotContainsString('Append link khi Copy', $beforeGen);
    }

    public function test_copy_only_mutates_output_after_clipboard_success(): void
    {
        $panel = $this->js('components/ShareGeneratePanel.jsx');
        self::assertStringContainsString('const wrote = await writeClipboard(payload.text)', $panel);
        self::assertStringContainsString('if (!wrote.ok)', $panel);
        self::assertStringContainsString('notifyError', $panel);
        self::assertStringContainsString('copied_at', $panel);
        self::assertStringContainsString('onUpdateOutput(next)', $panel);
        self::assertStringContainsString('buildCopyPayload', $panel);
        self::assertStringContainsString('appendLink,', $panel);
        self::assertStringNotContainsString('appendLink || Boolean(out.append_link)', $panel);

        // Failure path returns before mutation.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!wrote\.ok\s*\)\s*\{[^}]*notifyError[^}]*return;/s',
            $panel,
        );
    }

    public function test_build_copy_payload_still_weighted_selects_assigned_links(): void
    {
        $copy = $this->js('services/copyComment.js');
        self::assertStringContainsString('buildCopyPayload', $copy);
        self::assertStringContainsString('eligibleLinks', $copy);
        self::assertStringContainsString('weightedPickByRemaining', $copy);
        self::assertStringContainsString('Boolean(opts.appendLink)', $copy);
    }

    public function test_copy_honors_shared_append_link_toggle_only(): void
    {
        $panel = $this->js('components/ShareGeneratePanel.jsx');
        self::assertStringContainsString('appendLink,', $panel);
        self::assertStringNotContainsString('appendLink || Boolean(out.append_link)', $panel);
        self::assertMatchesRegularExpression(
            '/buildCopyPayload\(\{[\s\S]*appendLink,[\s\S]*\}\)/',
            $panel,
        );
    }
}
