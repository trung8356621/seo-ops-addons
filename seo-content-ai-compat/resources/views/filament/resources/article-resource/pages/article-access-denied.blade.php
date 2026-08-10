<x-filament-panels::page>
    <div class="mx-auto max-w-2xl">
        <x-filament::section icon="heroicon-o-lock-closed">
            <x-slot name="heading">Bạn không có quyền chỉnh sửa bài viết này</x-slot>

            <x-slot name="description">
                Bài viết không thuộc quyền sở hữu của bạn và cũng không nằm trong project do bạn phụ trách.
            </x-slot>

            <x-filament::button
                tag="a"
                :href="\Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('index')"
                icon="heroicon-o-arrow-left"
                color="gray"
            >
                Quay lại danh sách bài viết
            </x-filament::button>
        </x-filament::section>
    </div>
</x-filament-panels::page>
