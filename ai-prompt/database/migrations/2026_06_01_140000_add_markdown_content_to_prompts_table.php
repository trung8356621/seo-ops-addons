<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'markdown_content')) {
                $table->longText('markdown_content')->nullable()->after('tools');
            }
        });

        DB::connection($this->connection)
            ->table('prompts')
            ->orderBy('id')
            ->chunkById(100, function ($prompts): void {
                foreach ($prompts as $prompt) {
                    $parts = DB::connection($this->connection)
                        ->table('prompt_parts')
                        ->where('prompt_id', (int) $prompt->id)
                        ->orderBy('position')
                        ->get();

                    if ($parts->isEmpty()) {
                        continue;
                    }

                    $markdownBlocks = [];

                    foreach ($parts as $part) {
                        $role = strtolower(trim((string) ($part->role ?? '')));
                        $heading = match ($role) {
                            'role' => 'Vai trò',
                            'context' => 'Bối cảnh',
                            'constraints' => 'Ràng buộc',
                            'formatting' => 'Định dạng đầu ra',
                            'global_constraints' => 'Ràng buộc tổng (Global)',
                            'task' => 'Nhiệm vụ',
                            'sub_task' => 'Nhiệm vụ phụ thuộc',
                            default => ucfirst($role !== '' ? $role : 'context'),
                        };

                        $name = trim((string) ($part->name ?? ''));
                        if (in_array($role, ['task', 'sub_task'], true) && $name !== '') {
                            $heading .= ': ' . $name;
                        }

                        $content = trim((string) ($part->content ?? ''));
                        if ($content === '') {
                            continue;
                        }

                        $block = '# ' . $heading . "\n" . $content;

                        $meta = json_decode((string) ($part->meta ?? '[]'), true);
                        if (is_array($meta)) {
                            $rules = trim((string) ($meta['rules'] ?? ''));
                            if ($rules !== '') {
                                $block .= "\n\nQuy tắc:\n" . $rules;
                            }

                            if ($role === 'sub_task') {
                                $specific = trim((string) ($meta['specific_constraints'] ?? ''));
                                if ($specific !== '') {
                                    $block .= "\n\nRàng buộc riêng (sub-prompt):\n" . $specific;
                                }
                            }
                        }

                        $markdownBlocks[] = $block;
                    }

                    if ($markdownBlocks === []) {
                        continue;
                    }

                    DB::connection($this->connection)
                        ->table('prompts')
                        ->where('id', (int) $prompt->id)
                        ->update([
                            'markdown_content' => implode("\n\n", $markdownBlocks),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'markdown_content')) {
                $table->dropColumn('markdown_content');
            }
        });
    }
};
