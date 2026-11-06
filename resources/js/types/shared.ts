// TEMPORARY until ADR-0018: hand-written mirror of the props shared by
// App\Http\Middleware\HandleInertiaRequests::share(). Replace with
// generated types once ADR-0018 is decided (follow-up F-3).
export type Direction = 'ltr' | 'rtl';

export interface SharedProps {
    locale: string;
    direction: Direction;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
