<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl space-y-2">
                    <p class="text-sm font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">
                        MCP and API setup
                    </p>
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        Connect Checkybot to agents and automation.
                    </h2>
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Use one API key for the REST control API and the MCP server. Keys are shown only once when created, then stored as a hash.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a
                        href="{{ $apiKeysUrl }}"
                        class="inline-flex items-center justify-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                    >
                        Create API key
                    </a>
                    <a
                        href="{{ $swaggerUrl }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900"
                    >
                        Open Swagger
                    </a>
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                    <x-heroicon-o-key class="h-5 w-5" />
                </div>
                <h3 class="mt-4 text-base font-semibold text-gray-950 dark:text-white">1. Create a key</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Go to Developer, API Keys, create a key, and copy the plaintext value immediately.
                </p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                    <x-heroicon-o-server-stack class="h-5 w-5" />
                </div>
                <h3 class="mt-4 text-base font-semibold text-gray-950 dark:text-white">2. Add MCP server</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Point your MCP client at <span class="font-mono text-xs">{{ $mcpEndpoint }}</span> with the bearer token header.
                </p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                    <x-heroicon-o-command-line class="h-5 w-5" />
                </div>
                <h3 class="mt-4 text-base font-semibold text-gray-950 dark:text-white">3. Call tools or REST</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Use MCP tools for agent workflows, or call the REST endpoints directly under <span class="font-mono text-xs">{{ $restBaseUrl }}</span>.
                </p>
            </div>
        </section>

        <section
            x-data="{
                tab: 'mcp',
                copied: null,
                copy(value, name) {
                    navigator.clipboard.writeText(value).then(() => {
                        this.copied = name;
                        setTimeout(() => this.copied = null, 1600);
                    });
                }
            }"
            class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
        >
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        x-on:click="tab = 'mcp'"
                        x-bind:class="tab === 'mcp' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                        class="rounded-md px-3 py-2 text-sm font-semibold transition"
                    >
                        MCP install
                    </button>
                    <button
                        type="button"
                        x-on:click="tab = 'rest'"
                        x-bind:class="tab === 'rest' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                        class="rounded-md px-3 py-2 text-sm font-semibold transition"
                    >
                        REST API
                    </button>
                    <button
                        type="button"
                        x-on:click="tab = 'tools'"
                        x-bind:class="tab === 'tools' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                        class="rounded-md px-3 py-2 text-sm font-semibold transition"
                    >
                        MCP tools
                    </button>
                </div>
            </div>

            <div class="p-5">
                <div x-show="tab === 'mcp'" class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">MCP configuration</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Set <span class="font-mono text-xs">CHECKYBOT_API_KEY</span> in your client environment.</p>
                        </div>
                        <button
                            type="button"
                            x-on:click="copy(@js($mcpConfig), 'mcp')"
                            class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
                        >
                            <span x-show="copied !== 'mcp'">Copy config</span>
                            <span x-cloak x-show="copied === 'mcp'">Copied</span>
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm leading-6 text-gray-100"><code>{{ $mcpConfig }}</code></pre>
                </div>

                <div x-cloak x-show="tab === 'rest'" class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">REST control API</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Every endpoint uses <span class="font-mono text-xs">Authorization: Bearer &lt;CHECKYBOT_API_KEY&gt;</span>.</p>
                        </div>
                        <button
                            type="button"
                            x-on:click="copy(@js($curlExample), 'curl')"
                            class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
                        >
                            <span x-show="copied !== 'curl'">Copy curl</span>
                            <span x-cloak x-show="copied === 'curl'">Copied</span>
                        </button>
                    </div>

                    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm leading-6 text-gray-100"><code>{{ $curlExample }}</code></pre>

                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ([
                            'GET /control/me',
                            'GET /control/projects',
                            'GET /control/projects/{project}',
                            'GET /control/projects/{project}/checks',
                            'PUT /control/projects/{project}/checks/{check}',
                            'PATCH /control/projects/{project}/checks/{check}/disable',
                            'POST /control/projects/{project}/runs',
                            'POST /control/projects/{project}/checks/{check}/runs',
                            'GET /control/runs?project={project}&limit=25',
                            'GET /control/failures?project={project}&limit=25',
                        ] as $endpoint)
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200">
                                {{ $endpoint }}
                            </div>
                        @endforeach
                    </div>
                </div>

                <div x-cloak x-show="tab === 'tools'" class="space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Available MCP tools</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Use these names in <span class="font-mono text-xs">tools/call</span> requests.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            'me',
                            'list_projects',
                            'get_project',
                            'list_checks',
                            'upsert_check',
                            'disable_check',
                            'trigger_run',
                            'latest_failures',
                        ] as $tool)
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200">
                                {{ $tool }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
