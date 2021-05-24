import Timeloop from '@/vue/Timeloop.vue';
import { createApp } from 'vue';

// App main
const main = async () => {
    // Create our vue instance
    const timeloop = createApp(Timeloop);
    // Mount the app
    const root = timeloop.mount('#fields-timeloop');

    return root;
};

// Execute async function
main().then( (root) => {
});
