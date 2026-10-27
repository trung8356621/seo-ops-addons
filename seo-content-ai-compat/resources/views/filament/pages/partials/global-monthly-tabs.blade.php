<div
    class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm dark:border-white/10 dark:bg-gray-900"
    role="tablist"
    aria-label="Tabs"
>
    <button
        type="button"
        wire:click="setActiveTab('setup')"
        role="tab"
        aria-selected="{{ $this->activeTab === 'setup' ? 'true' : 'false' }}"
        @class([
            'rounded-md px-3 py-1.5 text-xs sm:text-sm font-medium transition cursor-pointer',
            'bg-primary-600 text-white shadow-sm' => $this->activeTab === 'setup',
            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->activeTab !== 'setup',
        ])
    >
        Thiết lập
    </button>
    <button
        type="button"
        wire:click="setActiveTab('progress')"
        role="tab"
        aria-selected="{{ $this->activeTab === 'progress' ? 'true' : 'false' }}"
        @class([
            'rounded-md px-3 py-1.5 text-xs sm:text-sm font-medium transition cursor-pointer',
            'bg-primary-600 text-white shadow-sm' => $this->activeTab === 'progress',
            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->activeTab !== 'progress',
        ])
    >
        Tiến trình
    </button>
    <button
        type="button"
        wire:click="setActiveTab('history')"
        role="tab"
        aria-selected="{{ $this->activeTab === 'history' ? 'true' : 'false' }}"
        @class([
            'rounded-md px-3 py-1.5 text-xs sm:text-sm font-medium transition cursor-pointer',
            'bg-primary-600 text-white shadow-sm' => $this->activeTab === 'history',
            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $this->activeTab !== 'history',
        ])
    >
        Lịch sử
    </button>
</div>
