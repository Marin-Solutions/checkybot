<x-filament-panels::page>
    <style>
        .cb-docs {
            --cb-docs-panel: rgb(255 255 255);
            --cb-docs-panel-soft: rgb(249 250 251);
            --cb-docs-border: rgb(229 231 235);
            --cb-docs-text: rgb(17 24 39);
            --cb-docs-heading: rgb(3 7 18);
            --cb-docs-muted: rgb(75 85 99);
            --cb-docs-code: rgb(29 78 216);
            --cb-docs-subtle: rgb(239 246 255);
            display: grid;
            gap: 1rem;
            color: var(--cb-docs-text);
        }

        .dark .cb-docs {
            --cb-docs-panel: rgba(15, 23, 42, .68);
            --cb-docs-panel-soft: rgba(2, 6, 23, .52);
            --cb-docs-border: rgba(148, 163, 184, .22);
            --cb-docs-text: rgb(229 231 235);
            --cb-docs-heading: rgb(249 250 251);
            --cb-docs-muted: rgb(209 213 219);
            --cb-docs-code: rgb(191 219 254);
            --cb-docs-subtle: rgba(37, 99, 235, .16);
        }

        .cb-docs * {
            box-sizing: border-box;
        }

        .cb-docs a {
            text-decoration: none;
        }

        .cb-docs-hero,
        .cb-docs-panel,
        .cb-docs-step {
            border: 1px solid var(--cb-docs-border);
            border-radius: .75rem;
            background: var(--cb-docs-panel);
            box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
        }

        .cb-docs-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.25rem;
            padding: 1.25rem;
        }

        .cb-docs-kicker {
            margin: 0 0 .35rem;
            color: rgb(96 165 250);
            font-size: .8125rem;
            font-weight: 700;
        }

        .cb-docs h2,
        .cb-docs h3 {
            margin: 0;
            color: var(--cb-docs-heading);
            line-height: 1.25;
        }

        .cb-docs h2 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .cb-docs h3 {
            font-size: 1rem;
            font-weight: 700;
        }

        .cb-docs p {
            margin: .5rem 0 0;
            color: var(--cb-docs-muted);
            font-size: .875rem;
            line-height: 1.55;
        }

        .cb-docs-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .cb-docs-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.25rem;
            border-radius: .5rem;
            padding: .5rem .75rem;
            font-size: .875rem;
            font-weight: 700;
            transition: background-color .15s ease, border-color .15s ease;
        }

        .cb-docs-button-primary {
            border: 1px solid rgb(37 99 235);
            background: rgb(37 99 235);
            color: white;
        }

        .cb-docs-button-primary:hover {
            background: rgb(59 130 246);
        }

        .cb-docs-button-secondary {
            border: 1px solid var(--cb-docs-border);
            background: var(--cb-docs-panel-soft);
            color: var(--cb-docs-heading);
        }

        .cb-docs-button-secondary:hover {
            background: var(--cb-docs-subtle);
        }

        .cb-docs-steps {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .cb-docs-step {
            min-height: 9.5rem;
            padding: 1rem;
        }

        .cb-docs-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: .5rem;
            background: var(--cb-docs-subtle);
            color: rgb(37 99 235);
            font-size: .875rem;
            font-weight: 800;
        }

        .cb-docs-step h3 {
            margin-top: .875rem;
        }

        .cb-docs-code {
            display: inline;
            overflow-wrap: anywhere;
            color: var(--cb-docs-code);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .8125rem;
        }

        .cb-docs-panel {
            overflow: hidden;
        }

        .cb-docs-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            border-bottom: 1px solid var(--cb-docs-border);
            padding: 1rem;
        }

        .cb-docs-tab {
            border: 1px solid var(--cb-docs-border);
            border-radius: .5rem;
            background: var(--cb-docs-panel-soft);
            color: var(--cb-docs-muted);
            cursor: pointer;
            font-size: .875rem;
            font-weight: 700;
            padding: .5rem .75rem;
        }

        .cb-docs-tab-active {
            border-color: rgb(37 99 235);
            background: rgb(37 99 235);
            color: white;
        }

        .cb-docs-panel-body {
            padding: 1rem;
        }

        .cb-docs-section-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .cb-docs-pre {
            max-width: 100%;
            overflow-x: auto;
            border-radius: .625rem;
            background: rgb(2 6 23);
            color: rgb(226 232 240);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .8125rem;
            line-height: 1.55;
            margin: 0;
            padding: 1rem;
        }

        .cb-docs-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .625rem;
        }

        .cb-docs-tool-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .625rem;
        }

        .cb-docs-chip {
            overflow-wrap: anywhere;
            border: 1px solid var(--cb-docs-border);
            border-radius: .5rem;
            background: var(--cb-docs-panel-soft);
            color: var(--cb-docs-heading);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: .8125rem;
            padding: .625rem .75rem;
        }

        [x-cloak] {
            display: none !important;
        }

        @media (max-width: 900px) {
            .cb-docs-hero,
            .cb-docs-section-header {
                flex-direction: column;
            }

            .cb-docs-steps,
            .cb-docs-grid,
            .cb-docs-tool-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="cb-docs">
        <section class="cb-docs-hero">
            <div>
                <p class="cb-docs-kicker">MCP and API setup</p>
                <h2>Connect Checkybot to agents and automation.</h2>
                <p>
                    Use one API key for the REST control API and the MCP server. Keys are shown only once when created, then stored as a hash.
                </p>
            </div>

            <div class="cb-docs-actions">
                <a href="{{ $apiKeysUrl }}" class="cb-docs-button cb-docs-button-primary">Create API key</a>
                <a href="{{ $swaggerUrl }}" target="_blank" rel="noreferrer" class="cb-docs-button cb-docs-button-secondary">Open Swagger</a>
            </div>
        </section>

        <section class="cb-docs-steps">
            <div class="cb-docs-step">
                <span class="cb-docs-step-number">1</span>
                <h3>Create a key</h3>
                <p>Go to Developer, API Keys, create a key, and copy the plaintext value immediately.</p>
            </div>

            <div class="cb-docs-step">
                <span class="cb-docs-step-number">2</span>
                <h3>Add MCP server</h3>
                <p>Point your MCP client at <span class="cb-docs-code">{{ $mcpEndpoint }}</span> with the bearer token header.</p>
            </div>

            <div class="cb-docs-step">
                <span class="cb-docs-step-number">3</span>
                <h3>Call tools or REST</h3>
                <p>Use MCP tools for agent workflows, or call REST endpoints under <span class="cb-docs-code">{{ $restBaseUrl }}</span>.</p>
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
            class="cb-docs-panel"
        >
            <div class="cb-docs-tabs">
                <button
                    type="button"
                    x-on:click="tab = 'mcp'"
                    x-bind:class="tab === 'mcp' ? 'cb-docs-tab cb-docs-tab-active' : 'cb-docs-tab'"
                >
                    MCP install
                </button>
                <button
                    type="button"
                    x-on:click="tab = 'rest'"
                    x-bind:class="tab === 'rest' ? 'cb-docs-tab cb-docs-tab-active' : 'cb-docs-tab'"
                >
                    REST API
                </button>
                <button
                    type="button"
                    x-on:click="tab = 'tools'"
                    x-bind:class="tab === 'tools' ? 'cb-docs-tab cb-docs-tab-active' : 'cb-docs-tab'"
                >
                    MCP tools
                </button>
            </div>

            <div class="cb-docs-panel-body">
                <div x-show="tab === 'mcp'">
                    <div class="cb-docs-section-header">
                        <div>
                            <h3>MCP configuration</h3>
                            <p>Set <span class="cb-docs-code">CHECKYBOT_API_KEY</span> in your client environment.</p>
                        </div>
                        <button type="button" x-on:click="copy(@js($mcpConfig), 'mcp')" class="cb-docs-button cb-docs-button-secondary">
                            <span x-show="copied !== 'mcp'">Copy config</span>
                            <span x-cloak x-show="copied === 'mcp'">Copied</span>
                        </button>
                    </div>
                    <pre class="cb-docs-pre"><code>{{ $mcpConfig }}</code></pre>
                </div>

                <div x-cloak x-show="tab === 'rest'">
                    <div class="cb-docs-section-header">
                        <div>
                            <h3>REST control API</h3>
                            <p>Every endpoint uses <span class="cb-docs-code">Authorization: Bearer &lt;CHECKYBOT_API_KEY&gt;</span>.</p>
                        </div>
                        <button type="button" x-on:click="copy(@js($curlExample), 'curl')" class="cb-docs-button cb-docs-button-secondary">
                            <span x-show="copied !== 'curl'">Copy curl</span>
                            <span x-cloak x-show="copied === 'curl'">Copied</span>
                        </button>
                    </div>

                    <pre class="cb-docs-pre"><code>{{ $curlExample }}</code></pre>

                    <div class="cb-docs-grid" style="margin-top: 1rem;">
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
                            <div class="cb-docs-chip">{{ $endpoint }}</div>
                        @endforeach
                    </div>
                </div>

                <div x-cloak x-show="tab === 'tools'">
                    <div class="cb-docs-section-header">
                        <div>
                            <h3>Available MCP tools</h3>
                            <p>Use these names in <span class="cb-docs-code">tools/call</span> requests.</p>
                        </div>
                    </div>

                    <div class="cb-docs-tool-grid">
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
                            <div class="cb-docs-chip">{{ $tool }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
