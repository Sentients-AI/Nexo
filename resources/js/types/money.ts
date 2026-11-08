// TEMPORARY until ADR-0018: hand-written mirror of the money shape the
// server sends. Replace with generated types (follow-up F-3).
export interface Money {
    /** Integer minor units (sen), ADR-0013. */
    amount: number;
    currency: 'MYR';
}
