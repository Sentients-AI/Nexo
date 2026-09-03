# Definition of Done

A change is done when the items below that **apply to it** are satisfied. Not
every item applies to every change — a docs-only PR has no tests to add, a
tooling chore has no authorization impact. Judgement is expected; skipping an
item that *does* apply is not.

## Always

- [ ] Acceptance criteria / the stated problem are actually satisfied.
- [ ] `composer check` passes locally — `composer validate --strict`,
      `composer audit`, Pint, PHPStan, Pest.
- [ ] CI is green on the PR.
- [ ] No secrets, credentials, or real data committed.
- [ ] The diff is focused — no unrelated refactoring, formatting sweeps, or
      dependency bumps folded in.
- [ ] Self-review done: you have read your own diff line by line.
- [ ] PR reviewed and approved; review conversations resolved.

## When code behaviour changed

- [ ] Tests added or updated, and they fail without the change.
- [ ] A bug fix has a regression test.
- [ ] Static analysis was not weakened to pass (no broad ignores / baseline).

## When data or persistence is involved

- [ ] Migration is reversible or its irreversibility is called out.
- [ ] Money stored as integer minor units; foreign keys and indexes intentional.
- [ ] Destructive schema change flagged, with the impact on existing production
      data stated and reviewer sign-off.

## When access to owned data is involved

- [ ] Authorization checked at the route/action, not assumed from the UI.
- [ ] No cross-vendor / cross-user data leakage introduced.
- [ ] Input validated at the boundary; client-supplied amounts/IDs/state
      re-checked server-side.

## When a dependency is added

- [ ] Justified by the current phase, not "it's popular".
- [ ] Smallest option that solves the problem; transitive cost considered.

## Documentation

- [ ] Docs updated in the same PR as the change they describe.
- [ ] An **ADR is added** if the change moves a domain boundary, changes how
      data is isolated between vendors, changes the money flow, adds/removes a
      runtime dependency, or changes the deployment model
      (`docs/architecture/adr/0001-adr-template-and-process.md`).
- [ ] Product docs (`docs/product/`) updated if product scope changed.
- [ ] Screenshots/recording attached for user-visible UI changes.
