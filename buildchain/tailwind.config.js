  
// module exports
module.exports = {
    mode: 'jit',
    purge: {
        content: [
            '../src/templates/**/*.{twig,html}',
            '../src/assetbundles/company-management/src/vue/**/*.{vue,html}',
        ],
        layers: [
            'base',
            'components',
            'utilities',
        ],
        mode: 'layers',
        options: {
            whitelist: [
                '../src/assetbundles/company-management/src/css/components/*.css',
            ],
        }
    },
    theme: {
    },
    corePlugins: {},
    plugins: [],
};