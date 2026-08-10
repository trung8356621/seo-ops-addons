<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\GlobalAiChatService;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GlobalAiChatServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seo_ai_models');
        Schema::dropIfExists('api_connections');

        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key');
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category');
            $table->string('raw_model_name');
            $table->string('display_name');
            $table->integer('priority')->default(100);
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        $user = new User([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);
        $user->id = 77;
        $user->exists = true;
        $this->actingAs($user);
    }

    public function test_it_lists_text_models_and_calls_selected_gemini_model(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 77,
            'provider' => 'gemini',
            'name' => 'Gemini primary',
            'api_key' => 'secret-key',
            'is_global' => false,
            'status' => 'active',
        ]);

        $textModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => 'gemini-2.5-flash',
            'display_name' => 'Gemini Flash',
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::IMAGEN_PRO,
            'raw_model_name' => 'imagen-4',
            'display_name' => 'Imagen',
            'priority' => 200,
            'status' => SeoAiModel::STATUS_ACTIVE,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Xin chào từ Gemini']],
                    ],
                ]],
            ]),
        ]);

        $service = app(GlobalAiChatService::class);
        $models = $service->availableModels();
        $result = $service->chat(
            (int) $textModel->id,
            'Xin chào',
            [['role' => 'assistant', 'content' => 'Tôi đang nghe.']],
        );

        $this->assertCount(1, $models);
        $this->assertSame((int) $textModel->id, $models[0]['id']);
        $this->assertSame('Xin chào từ Gemini', $result['answer']);
        $this->assertSame('gemini', $result['provider']);

        Http::assertSent(static function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && ($payload['contents'][0]['role'] ?? null) === 'model'
                && ($payload['contents'][1]['parts'][0]['text'] ?? null) === 'Xin chào';
        });
    }
}
