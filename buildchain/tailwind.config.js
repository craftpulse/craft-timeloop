
// module exports
module.exports = {
    mode: 'jit',
    purge: {
        content: [
            '../src/templates/**/*.{twig,html}',
            '../src/assetbundles/timeloop/src/vue/**/*.{vue,html}',
        ],
        layers: [
            'base',
            'components',
            'utilities',
        ],
        mode: 'layers',
        options: {
            whitelist: [
                '../src/assetbundles/timeloop/src/css/components/*.css',
            ],
        }
    },
    theme: {
    },
    corePlugins: {},
    plugins: [],
};
