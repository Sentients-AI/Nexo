import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import Home from './Home.vue';

// Inertia's <Head> needs a running Inertia app; render it as a no-op here.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ name: 'Head', render: () => null }),
}));

describe('Storefront/Home', () => {
    it('renders the storefront heading', () => {
        const wrapper = mount(Home);

        expect(wrapper.get('h1').text()).toBe('Nexo');
    });
});
