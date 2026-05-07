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

            <a
                class="fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                href="{{ route('server-log-file-history.download', $file) }}"
            >
                Download
            </a>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            No collected log files yet.
        </div>
    @endforelse
</div>
