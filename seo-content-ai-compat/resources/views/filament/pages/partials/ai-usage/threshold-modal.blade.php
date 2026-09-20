{{-- Shared threshold modal for provider wallet --}}
@if ($editingThresholdConnectionId !== null)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 backdrop-blur-sm" wire:key="threshold-modal">
        <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
            <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">Cấu hình ngưỡng cảnh báo số dư</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dashboard sẽ cảnh báo khi số dư thấp hơn ngưỡng này.</p>
            <div class="mt-4">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Ngưỡng cảnh báo (USD)</label>
                <div class="relative mt-1">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">$</span>
                    <input
                        type="number"
                        step="0.5"
                        min="0"
                        wire:model="editingThresholdValue"
                        class="block w-full rounded-lg border-gray-300 pl-7 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    />
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <x-filament::button color="gray" size="sm" wire:click="closeEditThresholdModal">
                    Hủy
                </x-filament::button>
                <x-filament::button size="sm" wire:click="saveThreshold">
                    Lưu ngưỡng
                </x-filament::button>
            </div>
        </div>
    </div>
@endif
