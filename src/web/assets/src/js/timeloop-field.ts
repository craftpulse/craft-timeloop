import Timeloop from '@/vue/Timeloop.vue'
import { createApp } from 'vue'

// App main
const main = async () => {

    const timeloopFields = document.querySelectorAll('[id$=_timeloop')
    const timeloopFieldsToMount = new Object();

    timeloopFields.forEach( (timeloopField) => {
        
        let field = timeloopField.id.replace('fields-', '').replace('_timeloop', '')
        
        timeloopFieldsToMount[field] = {
            'id': '#' + timeloopField.id,
            'app': createApp({ ...Timeloop })
        }

    })

    const root = Object.entries(timeloopFieldsToMount).map(entry => {
        let field = entry[1]
        return field.app.mount(field.id)
    })

    return root;
};

// Execute async function
main().then( (root) => {
});