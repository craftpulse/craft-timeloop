// app.settings.js

// node modules
require('dotenv').config();
const path = require('path');

// settings
module.exports = {
    alias: {
        '@': path.resolve('../src/assetbundles/timeloop/src'),
    },
    copyright: '©2020 Percipio.London',
    entry: {
        'app': [
            '@/js/timeloop.js',
        ],
    },
    extensions: ['.ts', '.js', '.vue', '.json'],
    name: 'timeloop',
    paths: {
        dist: path.resolve('../src/assetbundles/timeloop/dist'),
    },
    urls: {
        publicPath: () => process.env.PUBLIC_PATH || '',
    },
};
