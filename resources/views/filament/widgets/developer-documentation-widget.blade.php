<x-filament-widgets::widget>
    <x-filament::section>
        <style>
            .cb-docs-widget {
                --cb-docs-widget-heading: rgb(3 7 18);
                --cb-docs-widget-muted: rgb(75 85 99);
                --cb-docs-widget-border: rgb(229 231 235);
                --cb-docs-widget-soft: rgb(249 250 251);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }

            .dark .cb-docs-widget {
                --cb-docs-widget-heading: rgb(249 250 251);
                --cb-docs-widget-muted: rgb(209 213 219);
                --cb-docs-widget-border: rgba(148, 163, 184, .35);
                --cb-docs-widget-soft: rgba(15, 23, 42, .76);
            }

            .cb-docs-widget h2 {
                margin: 0;
                color: var(--cb-docs-widget-heading);
                font-size: 1rem;
                font-weight: 700;
                line-height: 1.35;
            }

            .cb-docs-widget p {
                margin: .25rem 0 0;
                color: var(--cb-docs-widget-muted);
                font-size: .875rem;
                line-height: 1.5;
            }

            .cb-docs-widget-actions {
                display: flex;
                flex-wrap: wrap;
                gap: .5rem;
            }

            .cb-docs-widget-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 2.25rem;
                border-radius: .5rem;
                padding: .5rem .75rem;
                font-size: .875rem;
                font-weight: 700;
                text-decoration: none;
            }

            .cb-docs-widget-button-primary {
                border: 1px solid rgb(37 99 235);
                background: rgb(37 99 235);
                color: white;
            }

            .cb-docs-widget-button-primary:hover {
                background: rgb(59 130 246);
            }

            .cb-docs-widget-button-secondary {
                border: 1px solid var(--cb-docs-widget-border);
                background: var(--cb-docs-widget-soft);
                color: var(--cb-docs-widget-heading);
            }

            .cb-docs-widget-button-secondary:hover {
                background: rgba(30, 41, 59, .88);
            }

            @media (max-width: 900px) {
                .cb-docs-widget {
                    align-items: flex-start;
                    flex-direction: column;
                }
            }
        </style>

        <div class="cb-docs-widget">
            <div>
                <h2>Developer setup</h2>
                <p>Install the MCP server, create an API key, and see the REST control API from one place.</p>
            </div>

            <div class="cb-docs-widget-actions">
                <a
                    href="{{ \App\Filament\Pages\Documentation::getUrl() }}"
                    class="cb-docs-widget-button cb-docs-widget-button-primary"
                >
                    Open documentation
                </a>
                <a
                    href="{{ \App\Filament\Resources\ApiKeyResource::getUrl('index') }}"
                    class="cb-docs-widget-button cb-docs-widget-button-secondary"
                >
                    Manage API keys
                </a>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
