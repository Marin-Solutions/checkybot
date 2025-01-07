import preset from './vendor/filament/support/tailwind.config.preset'

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
    plugins: [],
}
