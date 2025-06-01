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
                "checkybot": {
                    50: '#ececf7',
                    100: '#cacae9',
                    200: '#a7a7dc',
                    300: '#8585ce',
                    400: '#6363c0',
                    500: '#4545ae',
                    600: '#37378b',
                    700: '#2a2a69',
                    800: '#1c1c46',
                    900: '#0e0e24',
                },
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
