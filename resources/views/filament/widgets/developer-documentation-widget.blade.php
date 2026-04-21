<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-1">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                    Developer setup
                </h2>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Install the MCP server, create an API key, and see the REST control API from one place.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ \App\Filament\Pages\Documentation::getUrl() }}"
                    class="inline-flex items-center justify-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                >
                    Open documentation
                </a>
                <a
                    href="{{ \App\Filament\Resources\ApiKeyResource::getUrl('index') }}"
                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900"
                >
                    Manage API keys
                </a>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
