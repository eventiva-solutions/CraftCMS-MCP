# Security

## Reporting a vulnerability
Please report security issues privately through a **GitHub private security advisory** on this repository (Security tab → *Report a vulnerability*) and do not open a public issue. We aim to acknowledge reports within 3 business days.

## Threat model
This plugin gives an AI client write access to site content, so treat each token like a password.

- A token acts with the Craft permissions of its user **and** its scopes (`read`, `write`, `delete`). The effective rights are the intersection of both.
- Admin-only functionality (sections, fields, entry types, plugins, users, project config, system settings) is not exposed through any tool.
- Tokens are stored as SHA-256 hashes; the plain token is shown once at creation.

## Recommended setup
1. Serve the site over **HTTPS only**.
2. Create a dedicated Craft user for MCP with the minimum permissions (for example: edit entries in specific sections, no admin rights).
3. Start with `read` only; add `write` for editors' workflows; enable `delete` only when really needed.
4. Restrict tokens to the sections they need and set an expiry date.
5. Prefer `create_draft` / `publish_draft` for changes to live content.
6. Review the audit log (**MCP → Audit-Log**) regularly; revoke tokens that are no longer used.
7. Take a database backup before large automated changes.
8. Do not test write access against a copy of production data that still points to production asset storage or production integrations.

## Built-in protections
- Rate limit of 120 requests per minute per token.
- SSRF protection for URL uploads (private/reserved IP ranges blocked, no redirects, 20 MB limit).
- Allow-listed `orderBy` values; all queries use Craft's query builder.
- Audit log with redaction of large values.

## Known limitations
- Bearer tokens only; no OAuth. A leaked token works until it is revoked or expires.
- The rate limiter is per token, not per IP.
