<template>

    <div
        :class="[
            'input grid grid-cols-7 gap-1 items-center bg-blue-200 rounded-md',
            utilities
        ]"
    >

        <div 
            class="flex flex-nowrap"   
        >

            <select-field
                :settings="settings"
                :options="ordinals"
                v-model:selected="timestring.ordinal"
            />

            <select-field
                :settings="settings"
                :options="days"
                v-model:selected="timestring.day"
            />

        </div>

    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
    import { useModelWrapper } from '@/js/utils/modelWrapper'
    import SelectField from '@/vue/components/SelectField.vue'

    export default defineComponent({

        components: {
            'select-field': SelectField,
        },

        props: {

            // Field Settings ( name, id, required, ... )
            settings: {
                type: Object,
                required: true,
            },

            // Tailwind Utilities
            utilities: {
                type: String,
                required: false,
            },

        },

        data: () => ({

            ordinals: {
                'first': 'First',
                'second': 'Second',
                'third': 'Third',
                'fourth': 'Fourth',
                'last': 'Last',
            },

            days: {
                'monday': 'Monday',
                'tuesday': 'Tuesday',
                'wednesday': 'Wednesday',
                'thursday': 'Thursday',
                'friday': 'Friday',
                'saturday': 'Saturday',
                'sunday': 'Sunday',
            },

            timestring: {
                ordinal: '',
                day: '',
            }

        }),

        setup(props, { emit }) {

            return {
                temp: useModelWrapper(props, emit, 'temp'),
            }

        },

    });

</script>
