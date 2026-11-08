// Vitest setup (vitest.config.ts → setupFiles).
import { afterEach, beforeEach } from 'vitest';
import { activateLocale } from '@/i18n';
import { reset as resetToasts } from '@/shared/ui/toasts';

// jsdom has no HTMLDialogElement.showModal/close. This stand-in models what
// the components rely on: `open`, focus moving into the dialog, Escape
// firing a cancelable `cancel`, and `close`. Real browser behaviour (inert
// background, focus trap) is checked manually and in Phase 9 browser tests.
const dialogProto = globalThis.HTMLDialogElement?.prototype as HTMLDialogElement | undefined;

if (dialogProto && typeof dialogProto.showModal !== 'function') {
    const onKeydown = (event: KeyboardEvent) => {
        const dialog = event.currentTarget as HTMLDialogElement;

        if (event.key === 'Escape' && dialog.open) {
            const cancel = new Event('cancel', { cancelable: true });

            if (dialog.dispatchEvent(cancel)) dialog.close();
        }
    };

    Object.assign(dialogProto, {
        showModal(this: HTMLDialogElement) {
            this.setAttribute('open', '');
            this.addEventListener('keydown', onKeydown);
            const target = this.querySelector<HTMLElement>('[autofocus]') ?? this.querySelector<HTMLElement>(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            );
            target?.focus();
        },
        close(this: HTMLDialogElement) {
            if (!this.hasAttribute('open')) return;
            this.removeAttribute('open');
            this.removeEventListener('keydown', onKeydown);
            this.dispatchEvent(new Event('close'));
        },
    });
    Object.defineProperty(dialogProto, 'open', {
        get(this: HTMLDialogElement) {
            return this.hasAttribute('open');
        },
        configurable: true,
    });
}

beforeEach(async () => {
    await activateLocale('en');
});

afterEach(() => {
    resetToasts();
    document.body.innerHTML = '';
    document.documentElement.style.overflow = '';
});
