<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestModelEvidenceService;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Tests\TestCase;

final class AgentTestModelEvidenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('prompt_result_routing_attempts');
        Schema::create('prompt_result_routing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_result_id')->nullable()->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('provider')->nullable();
            $table->string('provider_model')->nullable();
            $table->string('logical_model')->nullable();
            $table->string('state')->nullable();
            $table->boolean('attempted')->default(false);
        });
    }

    public function test_prompt_returns_only_successful_attempted_provider_model(): void
    {
        $this->insert(10, 'openrouter', 'skipped-model', null, 'SKIPPED', false, 1);
        $this->insert(10, 'openrouter', 'failed-model', null, 'FAILED', true, 2);
        $this->insert(10, 'google', 'gemini-2.5-flash', 'text.fast', 'SUCCESS', true, 3);
        $result = new PromptResult();
        $result->id = 10;
        $result->exists = true;

        self::assertSame(
            [['provider' => 'google', 'model' => 'gemini-2.5-flash']],
            (new AgentTestModelEvidenceService())->forPromptResult($result),
        );
    }

    public function test_prompt_falls_back_to_logical_model(): void
    {
        $this->insert(11, 'deepseek', null, 'deepseek-v3.2', 'SUCCESS', true, 1);
        $result = new PromptResult();
        $result->id = 11;
        $result->exists = true;

        self::assertSame(
            [['provider' => 'deepseek', 'model' => 'deepseek-v3.2']],
            (new AgentTestModelEvidenceService())->forPromptResult($result),
        );
    }

    public function test_task_batch_returns_two_distinct_models_and_deduplicates_duplicates(): void
    {
        $this->insert(21, 'deepseek', 'deepseek-v3.2', null, 'SUCCESS', true, 1);
        $this->insert(22, 'google', 'gemini-2.5-flash', null, 'SUCCESS', true, 1);
        $this->insert(23, 'google', 'gemini-2.5-flash', null, 'SUCCESS', true, 1);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $models = (new AgentTestModelEvidenceService())->forWorkflow([
            ['prompt_result_id' => 21],
            ['prompt_result_ids' => [22, 23]],
        ]);

        self::assertSame([
            ['provider' => 'deepseek', 'model' => 'deepseek-v3.2'],
            ['provider' => 'google', 'model' => 'gemini-2.5-flash'],
        ], $models);
        self::assertCount(1, DB::getQueryLog(), 'Task model evidence must use one batch query.');
    }

    public function test_task_without_result_ids_returns_no_models_without_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], (new AgentTestModelEvidenceService())->forWorkflow([['status' => 'completed']]));
        self::assertCount(0, DB::getQueryLog());
    }

    private function insert(
        int $resultId,
        ?string $provider,
        ?string $providerModel,
        ?string $logicalModel,
        string $state,
        bool $attempted,
        int $sequence,
    ): void {
        DB::table('prompt_result_routing_attempts')->insert([
            'prompt_result_id' => $resultId,
            'provider' => $provider,
            'provider_model' => $providerModel,
            'logical_model' => $logicalModel,
            'state' => $state,
            'attempted' => $attempted,
            'sequence' => $sequence,
        ]);
    }
}
