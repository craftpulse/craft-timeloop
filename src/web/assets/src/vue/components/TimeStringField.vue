<template>

    <div
        :class="[
            'flex flex-nowrap mb-0',
            utilities
        ]"
    >

        <select-field
            :options="ordinals"
            v-model:selected="timestring.ordinal"
            @change="$emit('update:ordinal', timestring.ordinal)"

        />

        <select-field
            :options="days"
            v-model:selected="timestring.day"
            @change="$emit('update:day', timestring.day)"
        />


    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { reactive, defineComponent } from 'vue'
    import SelectField from '@/vue/components/SelectField.vue'

    export default defineComponent({

        components: {
            'select-field': SelectField,
        },

        props: {

            // Tailwind Utilities
            utilities: {
                type: String,
                required: false,
            },

            ordinal: {
                type: String,
                default: 'first',
            },

            day: {
                type: String,
                default: 'monday',
            }

        },

        data: () => ({

            ordinals: {
                'none': 'None',
                'first': 'First',
                'second': 'Second',
                'third': 'Third',
                'fourth': 'Fourth',
                'last': 'Last',
            },

            days: {
                'none': 'None',
                'monday': 'Monday',
                'tuesday': 'Tuesday',
                'wednesday': 'Wednesday',
                'thursday': 'Thursday',
                'friday': 'Friday',
                'saturday': 'Saturday',
                'sunday': 'Sunday',
            },

        }),

        setup: (props) => {

            const timestring = reactive({
                ordinal: props.ordinal || 'first',
                day: props.day || 'monday',
            })

            return { timestring }

        }

    });

</script>
