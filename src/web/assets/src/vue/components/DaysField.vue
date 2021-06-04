<template>

    <div
        :class="[
            'input grid grid-cols-7 gap-1 items-center rounded-md mt-1',
            utilities
        ]"
    >

        <span
            v-for="(day, key, index) in weekDays" :key="key"
            :class="[
                'inline-flex items-center justify-center w-8 h-8 font-bold bg-white',
                'hover:bg-black hover:text-white cursor-pointer',
                days.includes(key) ? 'bg-black text-white' : '',
            ]"
            @click="selectDay(key)"
        >
            {{ day.shortName }}
        </span>

    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
    import { useModelWrapper } from '@/js/utils/modelWrapper'

    export default defineComponent({

        props: {
            // Initial Value
            days: {
                type: Array,
                required: true,
            },

            // Tailwind Utilities
            utilities: {
                type: String,
                required: false,
            },

        },

        data: () => ({

            weekDays: {
                'Monday': {
                    shortName: 'M',
                    dayOfWeek: 1,
                },
                'Tuesday': {
                    shortName: 'T',
                    dayOfWeek: 2,
                },
                'Wednesday': {
                    shortName: 'W',
                    dayOfWeek: 3,
                },
                'Thursday': {
                    shortName: 'T',
                    dayOfWeek: 4,
                },
                'Friday': {
                    shortName: 'F',
                    dayOfWeek: 5,
                },
                'Saturday': {
                    shortName: 'S',
                    dayOfWeek: 6,
                },
                'Sunday': {
                    shortName: 'S',
                    dayOfWeek: 7,
                }
            },

        }),

        setup(props, { emit }) {

            return {
                days: useModelWrapper(props, emit, 'days'),
            }

        },

        methods: {
            selectDay( day ) {

                if (this.days.includes(day)) {

                    this.days = this.days.filter(name => name !== day)
                        
                } else {

                    this.days.push(day)

                }

                const map = this.weekDays;
                // always sort our days

                this.days.sort((a, b) => {
                    return map[a].dayOfWeek - map[b].dayOfWeek
                })

            },


        }

    });

</script>
