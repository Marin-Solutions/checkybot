<div class="space-y-3">
    @forelse ($files as $file)
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
            <div class="min-w-0">
                <div class="truncate text-sm font-medium text-gray-950 dark:text-white">
                    {{ basename($file->log_file_name) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Collected {{ $file->created_at?->diffForHumans() ?? 'unknown' }}
                </div>
            </div>

            <x-filament::button
                :href="route('server-log-file-history.download', $file)"
                size="sm"
                tag="a"
            >
                Download
            </x-filament::button>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            No collected log files yet.
        </div>
    @endforelse
</div>
