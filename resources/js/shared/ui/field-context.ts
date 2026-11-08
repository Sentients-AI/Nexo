// Field provides ids and state to the control inside it, so a label, hint
// and error are wired with aria-describedby / aria-invalid without the page
// passing ids around (NFR-A11Y-02).
import { inject, type ComputedRef, type InjectionKey } from 'vue';

export interface FieldContext {
    controlId: string;
    describedBy: ComputedRef<string | undefined>;
    invalid: ComputedRef<boolean>;
    required: ComputedRef<boolean>;
}

export const FIELD_CONTEXT: InjectionKey<FieldContext> = Symbol('nexo.field');

export function useFieldContext(): FieldContext | null {
    return inject(FIELD_CONTEXT, null);
}
