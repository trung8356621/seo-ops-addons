<x-filament-panels::page>
    <div class="space-y-6 max-w-5xl">
        <header class="space-y-2">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Tạo Content Project product thật → gắn original media → test Sprite / Parent-Child / Auto trong modal editor.
                Không generate ảnh AI ở bước fixture.
            </p>
            @if ($articleId)
                <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                    Canary Product · article #{{ $articleId }}
                </span>
            @endif
        </header>

        <form wire:submit="createFixture" class="space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            {{ $this->form }}
            <div class="flex gap-2">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="createFixture">
                    <span wire:loading.remove wire:target="createFixture">Tạo dự án sản phẩm thử nghiệm</span>
                    <span wire:loading wire:target="createFixture" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        Đang tạo…
                    </span>
                </x-filament::button>
            </div>
        </form>

        @if ($lastCreate)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-800 dark:bg-emerald-950/40">
                <p><strong>project</strong> #{{ $lastCreate['project_id'] }} ·
                    <strong>article</strong> #{{ $lastCreate['article_id'] }} ·
                    media: {{ implode(',', $lastCreate['original_media_ids'] ?? []) }}
                </p>
                <div class="mt-2 flex flex-wrap gap-3">
                    <a class="text-primary-600 underline" href="{{ $lastCreate['editor_url'] }}">Mở Product Editor</a>
                    <a class="text-primary-600 underline" href="{{ $lastCreate['project_url'] }}">Mở Content Project</a>
                </div>
            </div>
        @endif

        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-medium">Canary readiness</h2>
                <x-filament::button size="sm" color="gray" wire:click="refreshReadiness" wire:loading.attr="disabled" wire:target="refreshReadiness">
                    <span wire:loading.remove wire:target="refreshReadiness">Kiểm tra lại</span>
                    <span wire:loading wire:target="refreshReadiness">…</span>
                </x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="loadPromptPreview" wire:loading.attr="disabled" wire:target="loadPromptPreview">
                    <span wire:loading.remove wire:target="loadPromptPreview">Preview prompts</span>
                    <span wire:loading wire:target="loadPromptPreview">…</span>
                </x-filament::button>
                <x-filament::button size="sm" color="danger" wire:click="discardGenerated"
                    wire:confirm="Chỉ discard generated canary — giữ original + article + project?"
                    wire:loading.attr="disabled"
                    wire:target="discardGenerated">
                    <span wire:loading.remove wire:target="discardGenerated">Xóa kết quả canary đã generate</span>
                    <span wire:loading wire:target="discardGenerated">…</span>
                </x-filament::button>
                @if ($this->editorUrl())
                    <a class="text-sm text-primary-600 underline" href="{{ $this->editorUrl() }}">Mở gallery modal trên editor</a>
                @endif
            </div>

            @if ($readiness)
                <ul class="space-y-1 text-sm">
                    @foreach ($readiness['items'] as $item)
                        <li class="flex gap-2">
                            <span class="w-28 font-mono text-xs
                                @if ($item['status'] === 'OK') text-emerald-600
                                @elseif ($item['status'] === 'Không hỗ trợ') text-amber-600
                                @else text-rose-600 @endif">
                                {{ $item['status'] }}
                            </span>
                            <span>{{ $item['label'] }} — {{ $item['detail'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-gray-500">
                    Auto resolved: <code>{{ $readiness['resolved_auto_mode'] ?? '—' }}</code>
                    · originals: {{ implode(',', $readiness['original_media_ids'] ?? []) }}
                </p>
            @else
                <p class="text-sm text-gray-500">Tạo fixture hoặc truyền ?articleId=… để kiểm tra.</p>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-2 text-sm">
            <h2 class="font-medium">Manual test sequence</h2>
            <ol class="list-decimal pl-5 space-y-1">
                <li><strong>Test A — Mode 1 PASS:</strong> Editor → Product Gallery → Sprite → Run. Expect sprite / ai_children hoặc original_images, gallery_ready.</li>
                <li><strong>Test B — Mode 1 FALLBACK:</strong> Sprite invalid → original_images, project tiếp tục.</li>
                <li><strong>Test C — Mode 2:</strong> Parent/Child, shots=3 → parent + 3 child serial → parent_children hoặc fallback original.</li>
                <li><strong>Test D — Auto:</strong> Gemini native supported → parent_child; unknown/unsupported → sprite.</li>
            </ol>
            <p class="text-xs text-gray-500">
                CLI: <code>php artisan seo:product-gallery-parent-child-canary {{ $articleId ?: 'ARTICLE_ID' }} --dry-run --model=gemini-2.5-flash-image</code>
            </p>
        </section>

        @if ($promptPreview)
            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-3 text-sm">
                <h2 class="font-medium">Prompt preview (no AI)</h2>
                <p class="text-xs text-gray-500">
                    refs: {{ implode(',', $promptPreview['meta']['reference_media_ids'] ?? []) }}
                    · provider/model: {{ $promptPreview['meta']['provider'] ?? '' }} / {{ $promptPreview['meta']['model'] ?? '' }}
                    · shots: {{ $promptPreview['meta']['requested_shots'] ?? '' }}
                    · auto: {{ $promptPreview['meta']['resolved_auto_mode'] ?? '' }}
                </p>
                @foreach ([
                    'mode1' => 'Mode 1 sprite',
                    'mode2_plan' => 'Mode 2 planner',
                    'mode2_parent' => 'Mode 2 parent',
                    'mode2_child' => 'Mode 2 child (sample shot)',
                ] as $key => $label)
                    @php $block = $promptPreview[$key] ?? []; @endphp
                    <div>
                        <h3 class="font-medium">{{ $label }}
                            @if (!($block['ok'] ?? false))
                                <span class="text-rose-600">({{ $block['error'] ?? 'fail' }})</span>
                            @endif
                        </h3>
                        <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs dark:bg-gray-900">{{ $block['compiled'] ?? '' }}</pre>
                    </div>
                @endforeach
                <p class="text-xs text-gray-500">{{ $promptPreview['meta']['reference_note'] ?? '' }}</p>
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700 space-y-2 text-sm">
            <h2 class="font-medium">Execution history</h2>
            @if ($executionHistory === [])
                <p class="text-gray-500">Chưa có execution.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead>
                            <tr class="border-b">
                                <th class="py-1 pr-2">id</th>
                                <th class="py-1 pr-2">mode</th>
                                <th class="py-1 pr-2">status</th>
                                <th class="py-1 pr-2">failure</th>
                                <th class="py-1 pr-2">started</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($executionHistory as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-1 pr-2">{{ $row['id'] }}</td>
                                    <td class="py-1 pr-2">{{ $row['mode'] }}</td>
                                    <td class="py-1 pr-2">{{ $row['status'] }}</td>
                                    <td class="py-1 pr-2">{{ $row['failure_reason'] }}</td>
                                    <td class="py-1 pr-2">{{ $row['started_at'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
