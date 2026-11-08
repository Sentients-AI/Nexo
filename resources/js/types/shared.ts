// TEMPORARY until ADR-0018: hand-written mirror of the props shared by
// App\Http\Middleware\HandleInertiaRequests::share(). Replace with
// generated types once ADR-0018 is decided (follow-up F-3).
import type { Direction, Locale } from '@/i18n/locales';

export type { Direction, Locale };

export interface SharedProps {
    locale: Locale;
    direction: Direction;
}

/**
 * Inertia flash data, sent with Inertia::flash('toast', [...]). Messages are
 * translation keys so every toast is translated in the browser like the
 * rest of the UI.
 */
export interface FlashToast {
    tone: 'success' | 'info' | 'error';
    key: string;
    params?: Record<string, string | number>;
}

export interface Flash {
    toast?: FlashToast;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
        flashDataType: Flash;
    }
}
