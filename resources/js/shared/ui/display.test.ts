// StatusBadge, SurfaceCard, LoadingSkeleton, EmptyState, MoneyPrice.
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { activateLocale } from '@/i18n';
import { axeViolations } from '@/test/axe';
import EmptyState from './EmptyState.vue';
import LoadingSkeleton from './LoadingSkeleton.vue';
import MoneyPrice from './MoneyPrice.vue';
import StatusBadge from './StatusBadge.vue';
import SurfaceCard from './SurfaceCard.vue';

describe('StatusBadge', () => {
    it.each([
        ['neutral', 'bg-muted'],
        ['success', 'bg-success-bg'],
        ['warning', 'bg-warning-bg'],
        ['danger', 'bg-danger-bg'],
        ['info', 'bg-info-bg'],
    ] as const)('maps the %s tone to its token pair', async (tone, background) => {
        const wrapper = mount(StatusBadge, { props: { tone }, slots: { default: 'Paid' }, attachTo: document.body });

        expect(wrapper.classes()).toContain(background);
        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});

describe('SurfaceCard', () => {
    it('with a title: a region labelled by its heading at the requested level', async () => {
        const wrapper = mount(SurfaceCard, {
            props: { title: 'Order summary', level: 3 },
            slots: { default: '<p>Body</p>' },
            attachTo: document.body,
        });
        const heading = wrapper.get('h3');

        expect(wrapper.get('section').attributes('aria-labelledby')).toBe(heading.attributes('id'));
        expect(heading.text()).toBe('Order summary');
        expect(await axeViolations(wrapper.element)).toEqual([]);
    });

    it('without a title: a plain container', () => {
        expect(mount(SurfaceCard, { slots: { default: 'x' } }).find('section').exists()).toBe(false);
    });
});

describe('LoadingSkeleton', () => {
    it('is hidden from assistive tech and animates only without reduced motion', () => {
        const wrapper = mount(LoadingSkeleton, { props: { shape: 'block' } });

        expect(wrapper.attributes('aria-hidden')).toBe('true');
        expect(wrapper.classes()).toContain('motion-safe:animate-pulse');
        expect(wrapper.classes()).not.toContain('animate-pulse');
    });
});

describe('EmptyState', () => {
    it('renders a heading, description and action, with a decorative icon', async () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Your cart is empty', description: 'Find something you love.', level: 2 },
            slots: { icon: '<svg></svg>', action: '<a href="/">Start shopping</a>' },
            attachTo: document.body,
        });

        expect(wrapper.get('h2').text()).toBe('Your cart is empty');
        expect(wrapper.get('[aria-hidden="true"]').find('svg').exists()).toBe(true);
        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});

describe('MoneyPrice', () => {
    it.each(['en', 'ms', 'ar'] as const)('renders RM in %s inside an LTR isolate', async (locale) => {
        await activateLocale(locale);

        const bdi = mount(MoneyPrice, { props: { amount: { amount: 241850, currency: 'MYR' } } }).get('bdi');

        expect(bdi.attributes('dir')).toBe('ltr');
        expect(bdi.text()).toBe('RM 2,418.50');
    });

    it('labels sale and original prices for screen readers', async () => {
        const wrapper = mount(MoneyPrice, {
            props: { amount: { amount: 9900, currency: 'MYR' }, compareAt: { amount: 12900, currency: 'MYR' } },
            attachTo: document.body,
        });

        expect(wrapper.findAll('.sr-only').map((node) => node.text())).toEqual(['Price', 'Original price']);
        expect(wrapper.get('s').text()).toBe('RM 129.00');
        expect(await axeViolations(wrapper.element)).toEqual([]);
    });
});
