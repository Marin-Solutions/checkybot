<?php

    namespace App\Filament\Infolists\Actions;

    use Closure;
    use Filament\Infolists\Components\Actions\Action;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Js;

    class CopyAction extends Action
    {
        protected Closure|string|null $copyable = null;

        public static function getDefaultName(): ?string
        {
            return 'copy';
        }

        public function getLivewireClickHandler(): ?string
        {
            return null;
        }

        public function setUp(): void
        {
            parent::setUp();

            $this
                ->icon('heroicon-o-clipboard-document')
                ->label(__('Copy'))
                ->extraAttributes(function () {
                    $title = $this->getSuccessNotificationTitle() ?? __('Copied!');
                    return [
                        'x-data'     => '',
                        'x-on:click' => new HtmlString(
                            'window.navigator.clipboard.writeText(' . $this->getCopyable() . ');' .
                            " \$tooltip(" . Js::from($title) . ');'
                        ),
                    ];
                })
            ;
        }

        public function copyable( Closure|string|null $copyable ): static
        {
            $this->copyable = $copyable;
            return $this;
        }

        public function getCopyable(): ?string
        {
            return Js::from($this->evaluate($this->copyable));
        }
    }
