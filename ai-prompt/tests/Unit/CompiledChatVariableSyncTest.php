<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\CompiledChatVariableSync;
use Omnichannel\Addons\AiPrompt\Support\ProviderChatMessageBuilder;
use PHPUnit\Framework\TestCase;

final class CompiledChatVariableSyncTest extends TestCase
{
    public function test_align_noop_when_stamps_match_flat(): void
    {
        $system = "## Ràng buộc tổng (Global)\nNever invent facts.";
        $user = "## Nhiệm vụ\nWrite meta for Canvas bag.";
        $flat = $system."\n\n".$user;

        $out = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user,
        ], $flat);

        self::assertSame($system, $out['_compiled_system']);
        self::assertSame($user, $out['_compiled_user']);
    }

    public function test_align_updates_user_when_flat_appends_contract_after_system(): void
    {
        $system = "## Ràng buộc tổng (Global)\nKeep claims verifiable.";
        $user = "## Nhiệm vụ\nGenerate FAQ.";
        $contract = "FAQ_JSON_OUTPUT_CONTRACT_V1\nReturn ONLY valid JSON.";
        $flat = $system."\n\n".$user."\n\n".$contract;

        $out = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user, // stale vs flat
        ], $flat);

        self::assertSame($system, $out['_compiled_system']);
        self::assertStringContainsString('Generate FAQ.', (string) $out['_compiled_user']);
        self::assertStringContainsString('FAQ_JSON_OUTPUT_CONTRACT_V1', (string) $out['_compiled_user']);

        $messages = ProviderChatMessageBuilder::openAiCompatible(
            (string) $out['_compiled_system'],
            (string) $out['_compiled_user'],
            true,
        );
        self::assertSame('system', $messages[0]['role']);
        self::assertStringContainsString('Keep claims verifiable.', $messages[0]['content']);
        self::assertStringContainsString('FAQ_JSON_OUTPUT_CONTRACT_V1', $messages[1]['content']);
        self::assertStringNotContainsString('FAQ_JSON_OUTPUT_CONTRACT_V1', $messages[0]['content']);
    }

    public function test_align_refreshes_user_after_variable_recompile_shrink(): void
    {
        $system = "## Role\nAssistant";
        $staleUser = "## Nhiệm vụ\nReturn 20 keywords.";
        $freshFlat = $system."\n\n## Nhiệm vụ\nReturn 8 keywords.";

        $out = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $staleUser,
        ], $freshFlat);

        self::assertSame($system, $out['_compiled_system']);
        self::assertStringContainsString('Return 8 keywords.', (string) $out['_compiled_user']);
        self::assertStringNotContainsString('Return 20 keywords.', (string) $out['_compiled_user']);
    }

    public function test_align_collapses_to_user_when_system_prefix_no_longer_matches(): void
    {
        $out = CompiledChatVariableSync::align([
            '_compiled_system' => 'OLD SYSTEM',
            '_compiled_user' => 'OLD USER',
        ], 'COMPLETELY DIFFERENT FLAT');

        self::assertSame('', $out['_compiled_system']);
        self::assertSame('COMPLETELY DIFFERENT FLAT', $out['_compiled_user']);
    }

    public function test_user_append_does_not_change_or_duplicate_system_global_constraints(): void
    {
        $system = "## Ràng buộc tổng (Global)\nNever invent product facts.";
        $user = "## Nhiệm vụ\nWrite one meta description.";
        $appended = $user."\n\n[HOOK_INPUT]\ntitle: Canvas";
        $flat = $system."\n\n".$appended;

        $out = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user,
        ], $flat);

        self::assertSame($system, $out['_compiled_system']);
        self::assertSame(1, substr_count((string) $out['_compiled_system'], 'Never invent product facts.'));
        self::assertSame(0, substr_count((string) $out['_compiled_user'], 'Never invent product facts.'));
        self::assertStringContainsString('[HOOK_INPUT]', (string) $out['_compiled_user']);
    }

    public function test_provider_messages_do_not_leak_compiled_metadata_keys(): void
    {
        $system = "## Ràng buộc tổng (Global)\nKeep claims verifiable.";
        $user = "## Nhiệm vụ\nSuggest a title.";
        $out = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user,
        ], $system."\n\n".$user);

        $messages = ProviderChatMessageBuilder::openAiCompatible(
            (string) $out['_compiled_system'],
            (string) $out['_compiled_user'],
            true,
        );

        $blob = json_encode($messages, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('_compiled_system', $blob);
        self::assertStringNotContainsString('_compiled_user', $blob);
    }

    public function test_retry_attempt_keeps_identical_semantic_messages(): void
    {
        $system = "## Role\nEditor";
        $user = "## Nhiệm vụ\nDraft outline.";
        $flat = $system."\n\n".$user;

        $attempt1 = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user,
        ], $flat);
        $attempt2 = CompiledChatVariableSync::align([
            '_compiled_system' => $system,
            '_compiled_user' => $user,
        ], $flat);

        self::assertSame(
            ProviderChatMessageBuilder::openAiCompatible((string) $attempt1['_compiled_system'], (string) $attempt1['_compiled_user'], true),
            ProviderChatMessageBuilder::openAiCompatible((string) $attempt2['_compiled_system'], (string) $attempt2['_compiled_user'], true),
        );
    }
}
