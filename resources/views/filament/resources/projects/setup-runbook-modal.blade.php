<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Run these checks from the Laravel application connected to {{ $record->name }}.
    </p>

    @foreach ($repairActions as $repairAction)
        <div
            @if (isset($repairAction['command']))
                x-data="{ copied: false, command: @js($repairAction['command']) }"
            @endif
            class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h3 class="text-sm font-medium text-gray-950 dark:text-white">{{ $repairAction['title'] }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $repairAction['detail'] }}</p>
                </div>

                @if (isset($repairAction['command']))
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/15 dark:bg-gray-950 dark:text-gray-100 dark:hover:bg-white/5 dark:focus:ring-offset-gray-950"
                        x-on:click="
                            navigator.clipboard.writeText(command).then(() => {
                                copied = true;
                                setTimeout(() => copied = false, 1600);
                            });
                        "
                    >
                        <span x-show="! copied">Copy</span>
                        <span x-cloak x-show="copied">Copied</span>
                    </button>
                @endif
            </div>

            @if (isset($repairAction['command']))
                <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded-md bg-gray-950 p-3 text-sm text-white">{{ $repairAction['command'] }}</pre>
            @endif
        </div>
    @endforeach
</div>
