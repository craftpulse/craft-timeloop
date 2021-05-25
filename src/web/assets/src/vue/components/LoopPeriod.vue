<template>
    <div
        :id="'fields-' + options.id + '-field'"
        class="field"

    >

        <div class="heading">
            <label
                :id="'fields-' + options.id + '-label'"
                :class="options.required ? 'required' : ''"
            >
                {{ options.label }}
            </label>
        </div>

        <div
            :id="'fields-' + options.id + '-instructions'"
            class="instructions">
            <span>
                {{ options.instructions }}
            </span>
        </div>

        <div class="flex flex-nowrap">

            <select-field
                :settings="options"
                :options="periods"
                v-model:selected="period.frequency"
            />

            <cycle-field
                v-if="period.frequency && period.frequency !== 'P3M'"
                :settings="options"
                :frequency="period.frequency"
                utilities="ml-8"
                v-model:cycle="period.cycle"
            />

        </div>

        <div
            class="flex flex-nowrap mt-8 items-start"
            v-if="period.frequency && period.frequency === 'P1W'"
        >
            <days-field
                :settings="options"
                :frequency="period.frequency"
                v-model:days="period.days"
            />
        </div>

        <div
            class="flex flex-nowrap mt-8 items-start"
            v-if="period.frequency && period.frequency === 'P1M'"
        >
            <time-string-field
                :settings="options"
                :frequency="period.frequency"
            />
        </div>

    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
    import CycleField from '@/vue/components/CycleField.vue'
    import DaysField from '@/vue/components/DaysField.vue'
    import TimeStringField from '@/vue/components/TimeStringField.vue'
    import SelectField from '@/vue/components/SelectField.vue'


    export default defineComponent({
        components: {
            'select-field': SelectField,
            'cycle-field': CycleField,
            'days-field': DaysField,
            'time-string-field': TimeStringField
        },

        props: {
            options: {
                type: Object,
                required: true,
            },
        },

        data: () => ({

            periods: {
                'P1D': 'Every Day',
                'P1W': 'Every Week',
                'P1M': 'Every Month',
                'P3M': 'Every Quarter',
                'P1Y': 'Every Year',
            },

            period: {
                frequency: 'P1D',
                days: [],
                cycle: 1,
                timestring: {
                    ordinal: null,
                    day: null
                }
            },

        }),

        methods: {
        },

        mounted() {
        },
    });

</script>
