# Architecture Guidelines

These are the day-to-day rules for structuring code. Boundary-shaping decisions
are recorded as ADRs (`docs/architecture/adr/`); these guidelines are the
defaults you follow *between* ADRs. The full domain architecture is **not**
built yet — do not pre-build it.

## Follow what exists

- Follow the accepted ADRs. ADR-0002 (vendor = the tenant record), ADR-0003
  (context-dependent vendor scoping), ADR-0004 (`OrderGroup` + one `Order` per
  vendor) are authoritative. Do not contradict them; supersede one with a new
  ADR if the decision genuinely changes.
- Respect the invariants in `docs/architecture/INVARIANTS.md`.
- Prefer Laravel conventions and explicit, boring code over cleverness.

## Layering

- **Controllers coordinate HTTP.** They validate input (via form requests),
  invoke one application action, and shape the response. No business rules, no
  multi-step orchestration, no money math in a controller.
- **Business rules live in the domain/application layer** and are testable
  without an HTTP request — a plain object you can construct and call.
- **Validation happens at the boundary it enters** — form request for HTTP,
  explicit validation for console/queue, signature check for webhooks.
- **Infrastructure stays at the edge.** Domain logic should not import the HTTP
  request, the session, or a specific external SDK. Pass what it needs as
  arguments or injected dependencies.

## Abstraction discipline

- **No premature abstraction.** Build the concrete thing first. Extract an
  interface when there is a second real implementation or a real test seam —
  not before.
- **No generic `Service` classes.** A class named `OrderService` that is a bag
  of unrelated methods is not a boundary. Name things for what they do
  (`PlaceOrder`, `ReserveStock`, `CalculateCommission`).
- **No repositories just to wrap Eloquent.** Eloquent is the data layer. Add a
  repository only when you need to hide a genuinely different storage concern
  behind a domain-shaped interface.
- **No interface without a boundary.** One implementation and no test-double
  need means no interface.
- **Prefer explicit dependencies** (constructor injection) over facades and
  service location inside domain code.
- **Prefer composition over inheritance.** Deep class hierarchies and "base
  class with protected helpers" are usually a shared collaborator in disguise.

## When to write an ADR

Write one when a change:

- moves a domain boundary or changes what a bounded context means;
- changes how data is isolated between vendors;
- changes the money flow (charges, transfers, commission, refunds);
- adds or removes a runtime dependency;
- changes the deployment or environment model;
- reverses an earlier ADR.

Do **not** write an ADR for routine engineering choices covered by these
guidelines or by `docs/engineering/`.
