<template>

    <div
        :class="[
            'input grid grid-cols-7 gap-1 items-center',
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
                @change="$emit('update:ordinal', timestring.ordinal)"
                
            />

            <select-field
                :settings="settings"
                :options="days"
                v-model:selected="timestring.day"
                @change="$emit('update:day', timestring.day)"
            />

        </div>

    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
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
                ordinal: 'first',
                day: 'monday',
            }

        }),

        mounted() {
            this.timestring.ordinal = this.ordinal
            this.timestring.day = this.day
        }

    });

</script>
