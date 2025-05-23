import preset from './vendor/filament/support/tailwind.config.preset'
import scrollbarHide from 'tailwind-scrollbar-hide'
import colors from 'tailwindcss/colors'

export default {
    presets: [preset],
    content: [
        "./app/**/*.php",
        "./resources/**/*.{blade.php,js}",
        "./vendor/filament/**/*.blade.php",
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                "base-gray": colors.gray,
            },
            fontFamily: {
                "inter-sans": ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            }
        },
    },
    plugins: [
        scrollbarHide
    ],
}
