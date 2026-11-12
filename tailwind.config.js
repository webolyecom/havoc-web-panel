/** Tailwind build:  npm install && npm run build   (the compiled public/assets/app.css ships in releases) */
module.exports = {
  darkMode: 'class',
  content: ['./views/**/*.php', './public/assets/app.js', './public/assets/files.js', './public/assets/cron.js', './public/assets/logs.js'],
  theme: {
    extend: {
      colors: {
        brand: 'rgb(var(--brand) / <alpha-value>)',
        // "ink": deep blue-black neutrals for the sidebar and dark mode
        ink: { 300: '#c3cbe0', 400: '#8a94b0', 500: '#636d8a', 700: '#26304a', 800: '#182036', 900: '#10172a', 950: '#0a0f1e' },
      },
    },
  },
};
