<template>
    <div
        :class="[
            'input flex flex-nowrap mb-0 flex-nowrap items-center',
            utilities
        ]"
    >
        <span>
            Repeats Every
        </span>

        <input
            class="w-16 text"
            autocomplete="off"
            type="Number"
            min="1"
            v-model.number="cycle"
        />

        <span>
            {{ frequencyLabel }}
        </span>
    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { computed, defineComponent } from 'vue'
    import { useModelWrapper } from '@/js/utils/modelWrapper'

    export default defineComponent({

        props: {
            // Initial Value
            cycle: {
                type: Number,
                default: 1
            },

            // Frequency in datePeriod
            frequency: {
                type: String,
                required: true,
            },

            // Tailwind Utilities
            utilities: {
                type: String,
                required: false,
            },

        },

        setup(props, { emit }) {

            const frequencyLabel = computed(() => {

                switch(props.frequency) {

                    case 'P1D':
                        return 'day(s)'

                    case 'P1W':
                        return 'week(s)'

                    case 'P1M':
                        return 'month(s)'

                    case 'P1Y':
                        return 'year(s)'

                }

            });

            return {
                frequencyLabel,
                cycle: useModelWrapper(props, emit, 'cycle'),
            }
        },

    });

</script>
