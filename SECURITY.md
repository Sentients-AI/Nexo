# Security Policy

This repository is public. Treat everything committed here as world-readable,
forever.

## Reporting a Vulnerability

Do **not** open a public GitHub issue for a suspected vulnerability.

Report it privately through GitHub's **"Report a vulnerability"** advisory form
on this repository (Security → Advisories), or to the repository maintainer.

Please include enough detail to reproduce the issue safely, without attaching
real customer data, production credentials, or a working exploit against live
infrastructure.

## In Scope

- authentication or authorization bypass
- cross-vendor data exposure
- payment or commission manipulation
- privilege escalation
- sensitive information disclosure
- webhook signature-verification failures
- injection (SQL, command, template, header)
- insecure file upload or path handling

## Secrets

Never commit:

- API keys, passwords, private keys, or tokens
- payment-provider secrets
- production credentials or connection strings
- customer data or database dumps containing real user information

Secrets belong in environment variables and untracked `.env` files. If a secret
is committed, treat it as compromised: rotate it, then remove it from history.

## Responsible Disclosure

We will acknowledge a valid report, keep you informed of remediation progress,
and credit you if you wish once a fix is released.

Engineering-side security expectations for contributors are documented in
[`docs/engineering/security.md`](docs/engineering/security.md).
