<template>
    <div 
        :class="[ 
                'input',
                utilities
            ]"
        >
        <div class="select">
            <select
                :id="settings.id"
                :name="settings.name"
                :aria-describedby="settings.name"
                v-model="selected"
            >
                <option v-for="(label, key) in options" :key="key" :value="key">
                    {{ label }}
                </option>
            </select>
        </div>
    </div>
</template>

<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
    import { useModelWrapper } from '@/js/utils/modelWrapper'

    export default defineComponent({

        props: {
            
            // Options for the select field
            options: {
                type: Object,
                required: true,
            },
            // Field Settings ( name, id, required, ... )
            settings: {
                type: Object,
                required: true,
            },
            selected: {
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
            return {
                selected: useModelWrapper(props, emit, 'selected'),
            }
        },

    });

</script>