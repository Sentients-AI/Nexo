# Contributing to Nexo

Thank you for contributing to Nexo.

Nexo follows an issue-driven, pull-request-based development workflow.

## Before You Start

1. Read the relevant product documentation.
2. Check existing issues before creating duplicate work.
3. For architectural changes, review the relevant ADRs.
4. For non-trivial work, create or update an issue before implementation.

## Development Workflow

```text
Issue
  ↓
Implementation plan
  ↓
Branch
  ↓
Implementation
  ↓
Tests
  ↓
Pull Request
  ↓
Review
  ↓
CI
  ↓
Merge
```
Branches

The default branch is main.

Feature work must be performed on a dedicated branch.

See docs/engineering/ for the complete engineering operating model.

Pull Requests

Every pull request must:

explain the problem being solved;
describe the solution;
include relevant tests;
identify architectural consequences;
document unresolved concerns;
pass required CI checks.
Questions

If you are unsure whether a change requires an ADR, architectural review,
or additional tests, raise the question before implementation.


We'll expand this when we create the actual engineering operating model.

---