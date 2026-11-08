import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { activateLocale } from './i18n';

const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

void createInertiaApp({
    title: (title) => (title ? `${title} · Nexo` : 'Nexo'),
    resolve: async (name, page) => {
        const component = pages[`./pages/${name}.vue`];

        if (!component) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        // Load the page's locale before it renders, on the first visit and on
        // every client-side visit (switching between /, /ms and /ar included).
        const [resolved] = await Promise.all([component(), activateLocale(page?.props.locale)]);

        return resolved;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
