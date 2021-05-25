<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue';
    import LoopPeriod from '@/vue/components/LoopPeriod.vue'

    export default defineComponent({
        components: {
            'loop-period': LoopPeriod,
        },
    });

</script>
