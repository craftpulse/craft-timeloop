<template>
    <timeloop></timeloop>
</template>

<script lang="ts">
// Async load the Vue 3 APIs we need from the Vue ESM
import { defineAsyncComponent, defineComponent } from 'vue';

//import Timeloop from '@/vue/Timeloop.vue';

export default defineComponent({
    components: {
        'timeloop': defineAsyncComponent(() => import(/* webpackChunkName: "timeloop" */ '@/vue/Timeloop.vue')),
    },
    data: () => ({
    }),
    methods: {
    },
    mounted() {
    },
});

</script>