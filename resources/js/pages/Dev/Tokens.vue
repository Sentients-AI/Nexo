<script setup lang="ts">
// Local-only token gallery (routes/local.php). Everything here is read from
// tokens.css itself, so the gallery cannot drift from the stylesheet.
import tokensCss from '../../../css/tokens.css?raw';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { DECORATIVE_COLOR_TOKENS, evaluateContrast } from '@/design/contrast-pairs';
import { readRootTokens, resolveToken } from '@/design/tokens';

const tokens = readRootTokens(tokensCss);
const names = [...tokens.keys()];
const withPrefix = (prefix: string) => names.filter((name) => name.startsWith(prefix));
const short = (name: string, prefix: string) => name.slice(prefix.length);

const palette = withPrefix('--nx-palette-');
const colors = withPrefix('--nx-color-');
const textSizes = withPrefix('--nx-text-').filter((name) => !name.endsWith('-leading'));
const radii = withPrefix('--nx-radius-');
const shadows = withPrefix('--nx-shadow-');
const durations = withPrefix('--nx-duration-');
const eases = withPrefix('--nx-ease-');
const spaces = [1, 2, 3, 4, 6, 8, 12, 16];

const contrast = evaluateContrast(tokens);
const failing = computed(() => contrast.filter((result) => !result.passes).length);

const samples = [
    { lang: 'en', dir: 'ltr', label: 'English', text: 'Curated goods from independent makers', price: 'RM 1,249.00' },
    { lang: 'ms', dir: 'ltr', label: 'Bahasa Melayu', text: 'Barangan pilihan daripada pembuat bebas', price: 'RM 1,249.00' },
    { lang: 'ar', dir: 'rtl', label: 'العربية', text: 'منتجات مختارة من صنّاع مستقلين', price: 'RM 1,249.00' },
] as const;
</script>

<template>
    <Head title="Design tokens" />

    <main class="mx-auto max-w-content px-gutter py-section">
        <header class="border-b border-border pb-8">
            <p class="text-xs font-medium tracking-wide text-fg-muted uppercase">Local only · APP_ENV=local</p>
            <h1 class="mt-2 text-4xl font-semibold tracking-tight">Design tokens</h1>
            <p class="mt-3 max-w-2xl text-fg-muted">
                Source: <code class="font-mono text-sm">resources/css/tokens.css</code>. {{ names.length }} tokens;
                {{ contrast.length }} contrast pairs checked, {{ failing }} failing.
            </p>
        </header>

        <section class="mt-section" aria-labelledby="colour">
            <h2 id="colour" class="text-2xl font-semibold tracking-tight">Colour</h2>
            <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="name in colors" :key="name" class="min-w-0">
                    <div
                        class="h-16 rounded-md border border-border"
                        :style="{ background: `var(${name})` }"
                        data-test="swatch"
                    />
                    <p class="mt-2 truncate font-mono text-xs">{{ short(name, '--nx-color-') }}</p>
                    <p class="font-mono text-xs text-fg-muted">
                        {{ resolveToken(tokens, name) }}
                        <span v-if="name in DECORATIVE_COLOR_TOKENS"> · decorative</span>
                    </p>
                </li>
            </ul>

            <h3 class="mt-10 text-lg font-semibold">Palette</h3>
            <ul class="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-7 lg:grid-cols-11">
                <li v-for="name in palette" :key="name" class="min-w-0">
                    <div class="h-10 rounded-sm border border-border" :style="{ background: `var(${name})` }" />
                    <p class="mt-1 truncate font-mono text-xs text-fg-muted">{{ short(name, '--nx-palette-') }}</p>
                </li>
            </ul>
        </section>

        <section class="mt-section" aria-labelledby="contrast">
            <h2 id="contrast" class="text-2xl font-semibold tracking-tight">Contrast</h2>
            <p class="mt-2 text-fg-muted">Text needs 4.5:1; controls, focus rings and the accent need 3:1.</p>
            <div class="mt-6 overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full text-start text-sm">
                    <thead class="bg-subtle text-fg-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Sample</th>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Foreground</th>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Background</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Ratio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in contrast"
                            :key="`${row.foreground}|${row.background}`"
                            class="border-t border-border-subtle"
                            data-test="contrast-row"
                        >
                            <td class="px-4 py-2">
                                <span
                                    class="inline-block rounded-sm px-2 py-0.5 font-medium"
                                    :style="{ color: `var(${row.foreground})`, background: `var(${row.background})` }"
                                    >Aa</span
                                >
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ short(row.foreground, '--nx-color-') }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ short(row.background, '--nx-color-') }}</td>
                            <td class="px-4 py-2 text-end font-mono text-xs" :class="row.passes ? '' : 'text-danger'">
                                {{ row.ratio.toFixed(2) }} / {{ row.minimum }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-section" aria-labelledby="type">
            <h2 id="type" class="text-2xl font-semibold tracking-tight">Typography</h2>
            <div class="mt-6 space-y-3">
                <div v-for="name in textSizes" :key="name" class="flex items-baseline gap-6">
                    <span class="w-16 shrink-0 font-mono text-xs text-fg-muted">{{ short(name, '--nx-text-') }}</span>
                    <span
                        class="min-w-0 truncate"
                        :style="{ fontSize: `var(${name})`, lineHeight: `var(${name}-leading)` }"
                    >
                        Curated goods from independent makers
                    </span>
                </div>
            </div>

            <div class="mt-10 grid gap-4 md:grid-cols-3">
                <article
                    v-for="sample in samples"
                    :key="sample.lang"
                    :lang="sample.lang"
                    :dir="sample.dir"
                    class="rounded-lg border border-border bg-surface p-6 shadow-xs"
                >
                    <p class="text-xs font-medium text-fg-muted">{{ sample.label }}</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight">{{ sample.text }}</p>
                    <p class="mt-2 text-base text-fg-muted">{{ sample.text }}</p>
                    <p class="mt-4 text-lg font-medium">{{ sample.price }}</p>
                </article>
            </div>
        </section>

        <section class="mt-section grid gap-12 md:grid-cols-2" aria-label="Spacing and radius">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">Spacing</h2>
                <p class="mt-2 text-fg-muted">4px base unit: <code class="font-mono text-sm">p-4</code> is 16px.</p>
                <ul class="mt-6 space-y-2">
                    <li v-for="step in spaces" :key="step" class="flex items-center gap-4">
                        <span class="w-10 shrink-0 font-mono text-xs text-fg-muted">{{ step }}</span>
                        <span class="h-3 rounded-sm bg-accent" :style="{ width: `calc(var(--nx-space-unit) * ${step})` }" />
                    </li>
                </ul>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">Radius</h2>
                <ul class="mt-6 flex flex-wrap gap-4">
                    <li v-for="name in radii" :key="name" class="text-center">
                        <div class="size-16 border border-border-control bg-subtle" :style="{ borderRadius: `var(${name})` }" />
                        <p class="mt-2 font-mono text-xs text-fg-muted">{{ short(name, '--nx-radius-') }}</p>
                    </li>
                </ul>
            </div>
        </section>

        <section class="mt-section grid gap-12 md:grid-cols-2" aria-label="Elevation and motion">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">Elevation</h2>
                <ul class="mt-6 grid grid-cols-2 gap-6">
                    <li
                        v-for="name in shadows"
                        :key="name"
                        class="rounded-lg bg-surface p-6 font-mono text-xs text-fg-muted"
                        :style="{ boxShadow: `var(${name})` }"
                    >
                        {{ short(name, '--nx-shadow-') }}
                    </li>
                </ul>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">Motion</h2>
                <p class="mt-2 text-fg-muted">Hover a row. Durations drop to 0ms under reduced motion.</p>
                <ul class="mt-6 space-y-3">
                    <li v-for="name in durations" :key="name" class="group rounded-md bg-subtle p-2">
                        <div
                            class="h-3 w-8 rounded-full bg-accent transition-[width] group-hover:w-full"
                            :style="{ transitionDuration: `var(${name})` }"
                        />
                        <p class="mt-2 font-mono text-xs text-fg-muted">
                            {{ short(name, '--nx-duration-') }} · {{ tokens.get(name) }}
                        </p>
                    </li>
                </ul>
                <ul class="mt-6 space-y-1 font-mono text-xs text-fg-muted">
                    <li v-for="name in eases" :key="name">{{ short(name, '--nx-') }}: {{ tokens.get(name) }}</li>
                </ul>
            </div>
        </section>
    </main>
</template>
