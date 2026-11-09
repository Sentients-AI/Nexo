import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { activateLocale } from './i18n';
import { hreflangTags } from './i18n/alternates';

// Read from the element property: browsers blank the nonce attribute.
const cspNonce = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce;

const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

void createInertiaApp({
    title: (title) => (title ? `${title} · Nexo` : 'Nexo'),
    // CSP nonce for the <style> tags Inertia injects (progress bar, error dialog).
    ...(cspNonce ? { nonce: cspNonce } : {}),
    // Replaces the server-rendered hreflang links on every visit.
    serverHead: (page) => hreflangTags(page.props.alternates),
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
