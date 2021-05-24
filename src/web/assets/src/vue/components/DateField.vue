<template>
    <div class="bg-pink-500 text-3xl">
        Dynamic Loading a data prop: {{ date }}
    </div>

    <div class="bg-green-300 text-2xl">
        Loading component through Composition API non-async thanks {{ props }}
    </div>
</template>

<script lang="ts">
// Async load the Vue 3 APIs we need from the Vue ESM
import { defineComponent } from 'vue';

export default defineComponent({
    components: {
    },
    data: () => ({
        date: '2022-02-02',
        props: 'Andrew Welch'
    }),
    methods: {
    },
    mounted() {
    },
});

</script>