# Changelog

All notable changes to Nexo will be documented in this file.

The format follows the principles of [Keep a Changelog].

## [Unreleased]

### Added

- Initial engineering repository foundation.
- PHPStan + Larastan static analysis (level 6), wired into CI and `composer check`.
- `composer` scripts: `lint`, `test:lint`, `analyse`, `check`.
- Foundation test suite: health-check, storefront smoke test, architecture tests.
- Engineering documentation set under `docs/engineering/` — testing, code
  quality, security, error handling, database, git workflow, issue workflow,
  definition of done, architecture guidelines.
- Contributor setup and quality-gate instructions in `README.md`.

### Changed

- CI now runs static analysis (`vendor/bin/phpstan analyse`) as a quality gate.
- `.env.example` project defaults (`APP_NAME`, `DB_DATABASE`).
- Rewrote `SECURITY.md` as a clean, public-repo security policy.
- Rewrote the default Laravel `README.md` for Nexo.
- Normalised ADR filenames to the `NNNN-title.md` convention and fixed the
  broken cross-reference in `marketplace-migration.md`.
- Replaced the placeholder `docs/architecture/README.md` with an index.
- Tidied `CONTRIBUTING.md` and expanded the pull request template
  (screenshots, reviewer notes).
