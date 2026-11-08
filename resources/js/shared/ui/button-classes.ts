// Shared look for Button and button-styled Link, so both stay identical.

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

const BASE =
    'inline-flex items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap select-none ' +
    'transition-colors disabled:cursor-not-allowed disabled:opacity-60 aria-disabled:cursor-not-allowed aria-disabled:opacity-60';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'bg-accent text-on-accent hover:bg-accent-hover',
    secondary: 'border border-border-control bg-surface text-fg hover:bg-subtle',
    ghost: 'text-fg hover:bg-muted',
    danger: 'border border-danger bg-surface text-danger hover:bg-danger-bg',
};

// Heights keep every size at or above the 24px WCAG 2.2 target minimum;
// md and lg meet the 44px comfortable target.
const SIZES: Record<ButtonSize, string> = {
    sm: 'h-8 px-3 text-sm',
    md: 'h-11 px-4 text-sm',
    lg: 'h-12 px-6 text-base',
};

export function buttonClasses(variant: ButtonVariant = 'primary', size: ButtonSize = 'md', block = false): string {
    return [BASE, VARIANTS[variant], SIZES[size], block ? 'w-full' : ''].filter(Boolean).join(' ');
}
