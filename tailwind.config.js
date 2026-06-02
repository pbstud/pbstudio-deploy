/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.twig',
        '!./templates/backend/**/*.twig',
        '!./templates/mail/**/*.twig',
        './assets/js/components/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                terracota:          '#C17A6A',
                'terracota-dark':   '#A3614F',
                'terracota-light':  '#F0D9D4',
                sand:               '#F5F0EB',
                linen:              '#EDE8E3',
                charcoal:           '#2C2C2C',
                muted:              '#8C8C8C',
                'brand-teal':       '#67ccbb',
            },
            fontFamily: {
                sans:    ['Inter', 'sans-serif'],
                display: ['Libre Baskerville', 'serif'],
            },
        },
    },
};
