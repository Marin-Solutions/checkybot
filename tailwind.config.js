import preset from './vendor/filament/support/tailwind.config.preset'
import scrollbarHide from 'tailwind-scrollbar-hide'

export default {
    presets: [preset],
    content: [
        "./app/**/*.php",
        "./resources/**/*.{blade.php,js}",
        "./vendor/filament/**/*.blade.php",
    ],
    darkMode: 'class',
    theme: {
        extend: {},
    },
    plugins: [
        scrollbarHide
    ],
}
