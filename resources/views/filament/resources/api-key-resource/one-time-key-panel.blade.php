@php
    $fieldId = 'api-key-field-' . \Illuminate\Support\Str::random(8);
@endphp

<div
    x-data="{ copied: false, key: @js($plainTextKey) }"
    class="rounded-lg border border-success-300 bg-success-50 p-4 shadow-sm ring-1 ring-success-500/10 dark:border-success-700 dark:bg-success-950/40"
    wire:key="api-key-one-time-panel"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0 flex-1 space-y-3">
            <div>
                <h2 class="text-base font-semibold text-success-950 dark:text-success-100">
                    API key created
                </h2>

                <p class="mt-1 text-sm text-success-900 dark:text-success-200">
                    @if ($keyName)
                        {{ $keyName }} is ready. Copy the full key now; Checkybot stores only a masked preview and hash.
                    @else
                        Copy the full key now; Checkybot stores only a masked preview and hash.
                    @endif
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="sr-only" for="{{ $fieldId }}">API key</label>
                <input
                    id="{{ $fieldId }}"
                    x-ref="apiKeyInput"
                    class="block min-w-0 flex-1 rounded-md border border-success-300 bg-white px-3 py-2 font-mono text-sm text-gray-950 shadow-sm outline-none transition focus:border-success-500 focus:ring-2 focus:ring-success-500/30 dark:border-success-700 dark:bg-gray-950 dark:text-white"
                    readonly
                    type="text"
                    value="{{ $plainTextKey }}"
                />

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md bg-success-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-success-500 focus:outline-none focus:ring-2 focus:ring-success-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950"
                    x-on:click="
                        navigator.clipboard.writeText(key)
                            .then(() => {
                                $refs.apiKeyInput.select();
                                copied = true;
                                setTimeout(() => copied = false, 1600);
                            })
                            .catch(() => {
                                $refs.apiKeyInput.select();
                            });
                    "
                >
                    <span x-show="! copied">Copy key</span>
                    <span x-cloak x-show="copied">Copied</span>
                </button>
            </div>
        </div>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded-md border border-success-300 bg-white px-3 py-2 text-sm font-semibold text-success-800 shadow-sm transition hover:bg-success-100 focus:outline-none focus:ring-2 focus:ring-success-500 focus:ring-offset-2 dark:border-success-700 dark:bg-success-950 dark:text-success-100 dark:hover:bg-success-900 dark:focus:ring-offset-gray-950"
            wire:click="dismissOneTimeKey"
        >
            Done
        </button>
    </div>
</div>
