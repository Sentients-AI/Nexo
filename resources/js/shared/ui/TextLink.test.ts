import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axeViolations } from '@/test/axe';
import TextLink from './TextLink.vue';

describe('TextLink', () => {
    it('renders an internal link with its href', () => {
        const link = mount(TextLink, { props: { href: '/ms' }, slots: { default: 'Malay' } }).get('a');

        expect(link.attributes('href')).toBe('/ms');
        expect(link.attributes('target')).toBeUndefined();
    });

    it('opens external links safely in a new tab and says so to screen readers', () => {
        const link = mount(TextLink, {
            props: { href: 'https://example.com', external: true },
            slots: { default: 'Docs' },
        }).get('a');

        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
        expect(link.get('.sr-only').text()).toBe('(opens in a new tab)');
    });

    it('marks the current page', () => {
        const link = mount(TextLink, { props: { href: '/', current: true }, slots: { default: 'Home' } }).get('a');

        expect(link.attributes('aria-current')).toBe('page');
    });

    it('has no axe violations as text or as a button', async () => {
        const text = mount(TextLink, { props: { href: '/' }, slots: { default: 'Home' }, attachTo: document.body });
        const button = mount(TextLink, {
            props: { href: '/', appearance: 'primary' },
            slots: { default: 'Shop' },
            attachTo: document.body,
        });

        expect(await axeViolations(text.element)).toEqual([]);
        expect(await axeViolations(button.element)).toEqual([]);
    });
});
