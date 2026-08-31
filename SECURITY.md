# 7. `SECURITY.md`

Because this repository is public, this is important.

```markdown
# Security Policy

## Reporting a Vulnerability

Please do not disclose security vulnerabilities through public GitHub issues.

Security vulnerabilities should be reported privately to the repository
maintainers.

## Scope

Security concerns include, but are not limited to:

- authentication or authorization bypasses;
- cross-vendor data exposure;
- payment manipulation;
- privilege escalation;
- sensitive information disclosure;
- webhook verification failures;
- injection vulnerabilities;
- insecure file handling.

## Secrets

Never commit:

- API keys;
- passwords;
- private keys;
- payment provider secrets;
- production credentials;
- customer data;
- database dumps containing real user information.

Use environment variables for secrets and keep `.env` files outside version control.

## Responsible Disclosure

Please provide enough information to reproduce the issue safely without
including sensitive data.