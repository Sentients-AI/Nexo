// Shared look for text inputs and selects.
export const CONTROL_CLASSES =
    'block h-11 w-full min-w-0 rounded-md border bg-surface px-3 text-base text-fg ' +
    'placeholder:text-fg-muted transition-colors disabled:cursor-not-allowed disabled:bg-muted';

export function controlBorder(invalid: boolean): string {
    return invalid ? 'border-danger' : 'border-border-control hover:border-fg-muted';
}
