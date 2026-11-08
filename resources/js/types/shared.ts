// TEMPORARY until ADR-0018: hand-written mirror of the props shared by
// App\Http\Middleware\HandleInertiaRequests::share(). Replace with
// generated types once ADR-0018 is decided (follow-up F-3).
import type { Direction, Locale } from '@/i18n/locales';

export type { Direction, Locale };

export interface SharedProps {
    locale: Locale;
    direction: Direction;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
