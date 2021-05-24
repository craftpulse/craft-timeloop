<template>
    <div class="bg-yellow-400 text-2xl p-8 rounded-lg">
        Here comes our sexy Vue field again
    </div>
    <confetti></confetti>
    <date></date>
</template>

<script lang="ts">
// Async load the Vue 3 APIs we need from the Vue ESM
import { defineComponent } from 'vue';
import Confetti from '@/vue/Confetti.vue'
import DateField from '@/vue/components/DateField.vue'

export default defineComponent({
    components: {
        'confetti': Confetti,
        'date': DateField,
    },
    data: () => ({
    }),
    methods: {
    },
    mounted() {
    },
});

</script>