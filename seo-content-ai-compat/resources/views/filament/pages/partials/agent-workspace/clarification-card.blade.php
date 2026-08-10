@php
    $questions = is_array($structured['questions'] ?? null) ? $structured['questions'] : [];
@endphp

<div class="seo-agent-workspace__plan-card" x-data="{ answers: {} }">
    <div class="text-sm font-medium">{{ $structured['summary'] ?? $message['content'] ?? 'Cần thêm thông tin' }}</div>
    <div class="mt-2 space-y-3">
        @foreach ($questions as $q)
            @php
                $key = (string) ($q['key'] ?? '');
                $inputType = (string) ($q['input_type'] ?? 'text');
                $options = is_array($q['options'] ?? null) ? $q['options'] : [];
            @endphp
            @if ($key === '')
                @continue
            @endif
            <div>
                <label class="mb-1 block text-xs font-medium">{{ $q['question'] ?? $key }}</label>
                @if (in_array($inputType, ['select', 'multiselect'], true) && $options !== [])
                    <x-select
                        class="w-full text-xs"
                        data-answer-key="{{ $key }}"
                        x-on:change="answers[$event.target.dataset.answerKey] = $event.target.value"
                    >
                        <option value="">—</option>
                        @foreach ($options as $opt)
                            <option value="{{ $opt['value'] ?? '' }}">{{ $opt['label'] ?? ($opt['value'] ?? '') }}</option>
                        @endforeach
                    </x-select>
                @elseif ($inputType === 'textarea')
                    <textarea
                        class="w-full rounded-lg border border-gray-300 bg-white p-2 text-xs dark:border-gray-600 dark:bg-gray-800"
                        rows="3"
                        data-answer-key="{{ $key }}"
                        x-on:input="answers[$event.target.dataset.answerKey] = $event.target.value"
                    ></textarea>
                @elseif ($inputType === 'boolean')
                    <x-select
                        class="w-full text-xs"
                        data-answer-key="{{ $key }}"
                        x-on:change="answers[$event.target.dataset.answerKey] = $event.target.value"
                    >
                        <option value="">—</option>
                        <option value="1">Có</option>
                        <option value="0">Không</option>
                    </x-select>
                @else
                    <input
                        type="{{ $inputType === 'month' ? 'month' : ($inputType === 'number' ? 'number' : ($inputType === 'date' ? 'date' : 'text')) }}"
                        class="w-full rounded-lg border border-gray-300 bg-white p-2 text-xs dark:border-gray-600 dark:bg-gray-800"
                        data-answer-key="{{ $key }}"
                        x-on:input="answers[$event.target.dataset.answerKey] = $event.target.value"
                    />
                @endif
            </div>
        @endforeach
    </div>
    <div class="mt-3 flex gap-2">
        <button
            type="button"
            class="rounded-lg bg-primary-600 px-2 py-1 text-xs font-medium text-white"
            x-on:click="$wire.submitClarification(answers)"
            wire:loading.attr="disabled"
            wire:target="submitClarification"
        >
            <span wire:loading.remove wire:target="submitClarification">Gửi câu trả lời</span>
            <span wire:loading wire:target="submitClarification">Đang lập lại kế hoạch…</span>
        </button>
    </div>
    <p class="mt-2 text-xs opacity-60">Sau khi trả lời, agent đề xuất lại để bạn review — không tự chạy.</p>
</div>
