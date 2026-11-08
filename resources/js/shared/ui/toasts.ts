// Toast queue. Pages call push(); server responses send Inertia flash data
// ({ toast: { tone, key, params } }), which <ToastRegion> turns into a toast.
//
// Success and info toasts dismiss themselves after DURATION_MS, paused while
// the pointer or focus is on the region; errors stay until dismissed, so
// nobody has to read a failure against a clock (WCAG 2.2.1).
import { readonly, shallowRef } from 'vue';

export type ToastTone = 'success' | 'info' | 'error';

export interface Toast {
    id: number;
    tone: ToastTone;
    message: string;
}

export const DURATION_MS = 6000;

interface Timer {
    remaining: number;
    startedAt: number;
    handle?: ReturnType<typeof setTimeout>;
}

const queue = shallowRef<readonly Toast[]>([]);
const timers = new Map<number, Timer>();
let nextId = 1;
let paused = false;

function startTimer(id: number): void {
    const timer = timers.get(id);

    if (!timer || paused) return;

    timer.startedAt = Date.now();
    timer.handle = setTimeout(() => dismiss(id), timer.remaining);
}

export function push(tone: ToastTone, message: string): number {
    const id = nextId++;

    queue.value = [...queue.value, { id, tone, message }];

    if (tone !== 'error') {
        timers.set(id, { remaining: DURATION_MS, startedAt: Date.now() });
        startTimer(id);
    }

    return id;
}

export function dismiss(id: number): void {
    clearTimeout(timers.get(id)?.handle);
    timers.delete(id);
    queue.value = queue.value.filter((toast) => toast.id !== id);
}

export function pause(): void {
    if (paused) return;

    paused = true;

    for (const timer of timers.values()) {
        clearTimeout(timer.handle);
        timer.remaining = Math.max(0, timer.remaining - (Date.now() - timer.startedAt));
    }
}

export function resume(): void {
    if (!paused) return;

    paused = false;

    for (const id of timers.keys()) startTimer(id);
}

/** Test helper: clears every toast and timer. */
export function reset(): void {
    for (const id of [...timers.keys()]) dismiss(id);

    queue.value = [];
    paused = false;
}

export function useToasts() {
    return { toasts: readonly(queue), push, dismiss };
}
