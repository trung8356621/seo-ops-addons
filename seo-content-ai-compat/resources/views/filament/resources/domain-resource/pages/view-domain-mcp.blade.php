<div>
    <x-filament-panels::page>
        @php
            $mcpDoc = is_array($this->mcpCapabilityDoc ?? null) ? $this->mcpCapabilityDoc : [];
            $mcpMarkdown = (string) ($mcpDoc['markdown'] ?? '');
            $mcpHtml = (string) ($this->mcpHtml ?? '');
            $mcpCount = (int) ($mcpDoc['count'] ?? 0);
        @endphp

        <div
            class="space-y-4"
            x-data="{ showRaw: false }"
        >
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                <div class="font-semibold">Global MCP system-action catalog</div>
                <div class="mt-1">
                    CanonicalCapabilityRegistry documentation for developers.
                    Not WordPress site-feature flags. Domain id in URL is navigation shell only.
                    @if ($mcpCount > 0)
                        <span class="opacity-80">({{ $mcpCount }} capabilities)</span>
                    @endif
                </div>
            </div>

            <div class="flex justify-end">
                <x-filament::button
                    type="button"
                    size="sm"
                    color="gray"
                    x-on:click="showRaw = !showRaw"
                >
                    <span x-text="showRaw ? 'View formatted' : 'View raw Markdown'"></span>
                </x-filament::button>
            </div>

            <div
                x-show="!showRaw"
                class="seo-mcp-doc max-h-[40rem] overflow-auto rounded-lg border border-gray-200 bg-white p-5 text-sm leading-relaxed text-gray-800 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200
                    [&_h1]:mb-3 [&_h1]:mt-0 [&_h1]:text-xl [&_h1]:font-semibold
                    [&_h2]:mb-2 [&_h2]:mt-6 [&_h2]:text-lg [&_h2]:font-semibold
                    [&_h3]:mb-2 [&_h3]:mt-4 [&_h3]:text-base [&_h3]:font-semibold
                    [&_p]:my-2
                    [&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5
                    [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5
                    [&_li]:my-0.5
                    [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[0.85em] dark:[&_code]:bg-gray-800
                    [&_pre]:my-3 [&_pre]:overflow-auto [&_pre]:rounded-lg [&_pre]:border [&_pre]:border-gray-200 [&_pre]:bg-gray-50 [&_pre]:p-3 [&_pre]:text-xs dark:[&_pre]:border-gray-700 dark:[&_pre]:bg-gray-900
                    [&_pre_code]:bg-transparent [&_pre_code]:p-0
                    [&_a]:text-primary-600 [&_a]:underline dark:[&_a]:text-primary-400
                    [&_hr]:my-4 [&_hr]:border-gray-200 dark:[&_hr]:border-gray-700
                    [&_table]:my-3 [&_table]:w-full [&_table]:border-collapse [&_th]:border [&_th]:border-gray-200 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_td]:border [&_td]:border-gray-200 [&_td]:px-2 [&_td]:py-1 dark:[&_th]:border-gray-700 dark:[&_td]:border-gray-700"
            >
                @if ($mcpHtml !== '')
                    {!! $mcpHtml !!}
                @else
                    <p class="text-gray-500">No MCP capabilities available.</p>
                @endif
            </div>

            <pre
                x-show="showRaw"
                x-cloak
                class="max-h-[40rem] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-gray-800 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200"
            >{{ $mcpMarkdown !== '' ? $mcpMarkdown : 'No MCP capabilities available.' }}</pre>
        </div>
    </x-filament-panels::page>
</div>
