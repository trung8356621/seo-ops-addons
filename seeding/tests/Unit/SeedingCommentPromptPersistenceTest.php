<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Tests\TestCase;

/**
 * Persistence for Manager prompt + 20-slot ring history on omi_seeding.
 */
final class SeedingCommentPromptPersistenceTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = SeedingServiceConfig::CONNECTION;
        $this->bootSqliteMemoryConnection();

        try {
            DB::connection($this->connection)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('omi_seeding unavailable: '.$e->getMessage());
        }

        $this->ensureTables();
        $this->resetTables();
    }

    private function bootSqliteMemoryConnection(): void
    {
        Config::set('database.connections.'.$this->connection, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($this->connection);
    }

    public function test_manager_can_save_and_reload_prompt(): void
    {
        $service = new SeedingCommentPromptService();
        $saved = $service->savePromptBody("Custom prompt\n{{mcp_context}}\nDone.");
        self::assertStringContainsString('{{mcp_context}}', $saved);

        $reloaded = new SeedingCommentPromptService();
        self::assertSame($saved, $reloaded->getPromptBody());
        self::assertSame(
            "Custom prompt\nMCP HERE\nDone.",
            $reloaded->renderWithLatestPrompt('MCP HERE'),
        );
    }

    public function test_history_newest_first_and_caps_at_20(): void
    {
        $history = new SeedingCommentGenerateHistoryService();

        for ($i = 1; $i <= 21; $i++) {
            $history->record([
                'topic_id' => $i,
                'social' => 'threads',
                'quantity' => 1,
                'mcp_context' => "ctx-{$i}",
                'final_prompt' => "final-{$i}",
                'ai_output' => "out-{$i}",
                'provider' => 'p',
                'model' => 'm',
                'status' => SeedingCommentGenerateHistoryService::STATUS_SUCCESS,
                'error_message' => null,
            ]);
        }

        $latest = $history->latest();
        self::assertCount(20, $latest);
        self::assertSame(21, (int) $latest[0]->sequence);
        self::assertSame('ctx-21', $latest[0]->mcp_context);
        self::assertSame(2, (int) $latest[19]->sequence);
        self::assertSame('ctx-2', $latest[19]->mcp_context);

        // Entry 21 overwrote slot of sequence 1.
        $slotOfSeq1 = SeedingCommentGenerateHistoryService::slotForSequence(1);
        $overwritten = $history->findBySlot($slotOfSeq1);
        self::assertNotNull($overwritten);
        self::assertSame(21, (int) $overwritten->sequence);
        self::assertSame('ctx-21', $overwritten->mcp_context);
    }

    public function test_concurrent_record_does_not_corrupt_same_slot_payload(): void
    {
        $history = new SeedingCommentGenerateHistoryService();

        // Simulate two sequential locked writes (same transaction mechanism used under concurrency).
        $a = $history->record([
            'topic_id' => 100,
            'social' => 'facebook',
            'quantity' => 2,
            'mcp_context' => 'A',
            'final_prompt' => 'FA',
            'status' => SeedingCommentGenerateHistoryService::STATUS_SUCCESS,
        ]);
        $b = $history->record([
            'topic_id' => 101,
            'social' => 'threads',
            'quantity' => 3,
            'mcp_context' => 'B',
            'final_prompt' => 'FB',
            'status' => SeedingCommentGenerateHistoryService::STATUS_FAILED,
            'error_message' => 'x',
        ]);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame((int) $a->slot, (int) $b->slot);
        self::assertSame('A', $history->findBySlot((int) $a->slot)?->mcp_context);
        self::assertSame('B', $history->findBySlot((int) $b->slot)?->mcp_context);
        self::assertSame(1, (int) $a->sequence);
        self::assertSame(2, (int) $b->sequence);
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seeding_comment_prompt_settings')) {
            $schema->create('seeding_comment_prompt_settings', function ($table): void {
                $table->id();
                $table->longText('prompt_body');
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seeding_comment_generate_log_meta')) {
            $schema->create('seeding_comment_generate_log_meta', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('next_sequence')->default(0);
            });
        }

        if (! $schema->hasTable('seeding_comment_generate_logs')) {
            $schema->create('seeding_comment_generate_logs', function ($table): void {
                $table->unsignedTinyInteger('slot')->primary();
                $table->unsignedBigInteger('sequence')->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->string('social', 64)->nullable();
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->longText('mcp_context');
                $table->longText('final_prompt');
                $table->longText('ai_output')->nullable();
                $table->string('provider', 128)->nullable();
                $table->string('model', 191)->nullable();
                $table->string('status', 32);
                $table->text('error_message')->nullable();
                $table->timestamp('generated_at')->index();
            });
        }
    }

    private function resetTables(): void
    {
        DB::connection($this->connection)->table('seeding_comment_generate_logs')->delete();
        DB::connection($this->connection)->table('seeding_comment_generate_log_meta')->delete();
        DB::connection($this->connection)->table('seeding_comment_prompt_settings')->delete();
        DB::connection($this->connection)->table('seeding_comment_generate_log_meta')->insert([
            'id' => 1,
            'next_sequence' => 0,
        ]);
    }
}
