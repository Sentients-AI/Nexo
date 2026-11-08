import tokensCss from '../../../css/tokens.css?raw';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { contrastPairs } from '@/design/contrast-pairs';
import { readRootTokens } from '@/design/tokens';
import Tokens from './Tokens.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: defineComponent({ name: 'Head', render: () => null }),
}));

const tokens = readRootTokens(tokensCss);

describe('Dev/Tokens', () => {
    it('shows a swatch for every colour token', () => {
        const colorCount = [...tokens.keys()].filter((name) => name.startsWith('--nx-color-')).length;

        expect(mount(Tokens).findAll('[data-test="swatch"]')).toHaveLength(colorCount);
    });

    it('lists every contrast pair, none failing', () => {
        const wrapper = mount(Tokens);

        expect(wrapper.findAll('[data-test="contrast-row"]')).toHaveLength(contrastPairs(tokens).length);
        expect(wrapper.text()).toContain(', 0 failing');
    });

    it('renders the Arabic sample right to left', () => {
        const arabic = mount(Tokens).get('article[lang="ar"]');

        expect(arabic.attributes('dir')).toBe('rtl');
    });
});
