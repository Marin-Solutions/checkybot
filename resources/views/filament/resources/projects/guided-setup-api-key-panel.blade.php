@php
    $fieldId = 'guided-setup-api-key-field';
    $snippetFieldId = 'guided-setup-snippet-field';
@endphp

<div
    x-data="{ copiedKey: false, copiedSnippet: false, key: @js($plainTextKey), snippet: @js($snippet) }"
    class="rounded-lg border border-success-300 bg-success-50 p-4 shadow-sm ring-1 ring-success-500/10 dark:border-success-700 dark:bg-success-950/40"
    wire:key="guided-setup-api-key-panel"
>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1 space-y-2">
                <div>
                    <h3 class="text-base font-semibold text-success-950 dark:text-success-100">
                        API key created for this setup flow
                    </h3>

                    <p class="mt-1 text-sm text-success-900 dark:text-success-200">
                        @if ($keyName)
                            {{ $keyName }} is ready. Copy the full key now; Checkybot stores only a masked preview and hash.
                        @else
                            Copy the full key now; Checkybot stores only a masked preview and hash.
                        @endif
                    </p>

                    <p class="mt-1 text-sm text-success-900 dark:text-success-200">
                        The install snippet below has been updated with this key for the current session so you can copy everything in one pass.
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
                                    copiedKey = true;
                                    setTimeout(() => copiedKey = false, 1600);
                                })
                                .catch(() => {
                                    $refs.apiKeyInput.select();
                                });
                        "
                    >
                        <span x-show="! copiedKey">Copy key</span>
                        <span x-cloak x-show="copiedKey">Copied</span>
                    </button>
                </div>
            </div>

            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border border-success-300 bg-white px-3 py-2 text-sm font-semibold text-success-800 shadow-sm transition hover:bg-success-100 focus:outline-none focus:ring-2 focus:ring-success-500 focus:ring-offset-2 dark:border-success-700 dark:bg-success-950 dark:text-success-100 dark:hover:bg-success-900 dark:focus:ring-offset-gray-950"
                wire:click="dismissGuidedSetupApiKey"
            >
                Dismiss
            </button>
        </div>

        <div class="space-y-2">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-success-950 dark:text-success-100">Ready-to-copy install snippet</h3>
                    <p class="text-sm text-success-900 dark:text-success-200">Includes the one-time API key shown above.</p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-success-300 bg-white px-3 py-2 text-sm font-semibold text-success-800 shadow-sm transition hover:bg-success-100 focus:outline-none focus:ring-2 focus:ring-success-500 focus:ring-offset-2 dark:border-success-700 dark:bg-success-950 dark:text-success-100 dark:hover:bg-success-900 dark:focus:ring-offset-gray-950"
                    x-on:click="
                        navigator.clipboard.writeText(snippet)
                            .then(() => {
                                copiedSnippet = true;
                                setTimeout(() => copiedSnippet = false, 1600);
                            })
                            .catch(() => {
                                $refs.snippetInput.focus();
                                $refs.snippetInput.select();
                            });
                    "
                >
                    <span x-show="! copiedSnippet">Copy updated snippet</span>
                    <span x-cloak x-show="copiedSnippet">Snippet copied</span>
                </button>
            </div>

            <label class="sr-only" for="{{ $snippetFieldId }}">Install snippet</label>
            <textarea
                id="{{ $snippetFieldId }}"
                x-ref="snippetInput"
                class="block min-h-56 w-full rounded-md border border-success-300 bg-white px-3 py-2 font-mono text-sm text-gray-950 shadow-sm outline-none transition focus:border-success-500 focus:ring-2 focus:ring-success-500/30 dark:border-success-700 dark:bg-gray-950 dark:text-white"
                readonly
            >{{ $snippet }}</textarea>
        </div>
    </div>
</div>
