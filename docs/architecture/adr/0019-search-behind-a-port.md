# ADR-0019: Put catalogue search behind a port, implemented with MySQL first

- **Number:** 0019
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner (open decision D-8)

---

## Context

- The old repository ran Typesense through Laravel Scout.
- The brief lists Typesense in the existing stack, but says not every technology must remain.
- The demo catalogue is small: a few hundred to low thousands of curated products.
- Every extra service makes the demo harder for reviewers to run.

## Problem

What powers storefront search and filtering in v2?

## Decision drivers

- Relevance that is good enough for a curated catalogue.
- Facets: category, price range, vendor, rating, in-stock.
- Minimal infrastructure.
- A clean seam for a later engine.

## Considered alternatives

### A. Typesense from day one

- Better relevance and typo tolerance.
- Rejected for now: it adds a service and an index-consistency problem before it adds value.

### B. Laravel Scout's `database` engine

Thin. It hides the query shape needed for facets.

### C. A `CatalogueSearch` port with a MySQL implementation (chosen)

- `FULLTEXT` index on searchable product text, plus indexed facet columns on a denormalised `search_documents` table.
- The table is maintained by the Search module from Catalogue and Inventory outbox events.

## Decision

1. Search owns `search_documents`. There is one row per sellable product, built from Catalogue, Vendors, Inventory and Engagement events:
   - title;
   - brand;
   - category path;
   - vendor;
   - minimum and maximum price;
   - in-stock flag;
   - rating;
   - per-locale searchable text.

   It is never a source of truth, and it can be rebuilt with `php artisan search:rebuild`.
2. Search queries use `MATCH … AGAINST` in natural-language mode, with a LIKE fallback for queries shorter than the minimum token size. Facet counts come from grouped queries on indexed columns.
3. The `CatalogueSearch` interface is stable. A Typesense adapter can be added in Phase 8 if search quality measurably needs it, with a new ADR.
4. **Visibility rules** are applied when building documents (only sellable products), and re-checked when the product page loads.

## Positive consequences

- No extra service.
- Consistency is handled by the outbox.

## Negative consequences

- Weaker relevance and typo tolerance.
- Arabic stemming is limited in MySQL FULLTEXT.

## Risks

- Arabic search quality. Mitigation: normalise Arabic text (alef forms, diacritics) into the searchable text, and test it.

## Testing implications

- Search tests on MySQL: relevance ordering for fixtures, facet counts, hidden products never returned, and Arabic normalisation.

## Migration implications

- The Scout/Typesense dependency is not carried over.

## Related

- ADR-0017; Phase 0 §13.4.
