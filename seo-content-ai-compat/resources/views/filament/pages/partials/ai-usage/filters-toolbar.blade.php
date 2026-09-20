<div class="flex flex-wrap items-center gap-2">
    <div class="w-auto min-w-[9rem]">
        <x-select
            wire:model.live="usageDateRange"
            size="sm"
            wrapClass="!w-auto"
            class="text-xs"
        >
            <option value="today">Hôm nay</option>
            <option value="7d">7 ngày qua</option>
            <option value="30d">30 ngày qua</option>
            <option value="this_month">Tháng này</option>
        </x-select>
    </div>

    <div class="w-auto min-w-[9rem]">
        <x-select
            wire:model.live="usageAddonFilter"
            size="sm"
            wrapClass="!w-auto"
            class="text-xs"
        >
            <option value="all">Tất cả Addons</option>
            <option value="seo">SEO</option>
            <option value="seeding">Seeding</option>
        </x-select>
    </div>

    <x-filament::button
        wire:click="$refresh"
        size="sm"
        color="gray"
        icon="heroicon-o-arrow-path"
        wire:loading.attr="disabled"
        wire:target="$refresh"
    >
        <span wire:loading.remove wire:target="$refresh">Làm mới</span>
        <span wire:loading wire:target="$refresh">Đang tải...</span>
    </x-filament::button>
</div>
