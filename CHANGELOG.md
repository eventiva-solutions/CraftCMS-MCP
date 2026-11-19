# Release Notes for MCP Server

## 1.0.2 - 2026-11-20

- The connect instructions for **Codex** now show the tested setup (`codex mcp add … --bearer-token-env-var`): the token is kept in an environment variable instead of the Codex configuration file
- The server instructions sent to the AI client explain the approval workflow when the token requires approval, including to ask when a rejection reason is vague

## 1.0.1 - 2026-11-19

- Clearer before/after view in **MCP → Approvals**: words and punctuation are compared separately, heavily rewritten text is shown as old and new side by side instead of many tiny changes, and HTML entities are decoded
- Matrix blocks are only shown as “changed” when part of the block stayed the same; replaced blocks appear as removed and new
- Batch labels in the approvals list use an unambiguous date format (`2026-11-19 22:35`)
- Unit tests for the diff view

## 1.0.0 - 2026-11-19

First release.

### MCP server
- Streamable-HTTP MCP endpoint at `/mcp`, authenticated with bearer tokens (`Authorization: Bearer …` or `X-Mcp-Token`)
- 24 tools: search and read entries, assets, categories, tags, sections and entry types; create and update entries and drafts; upload and organize assets; manage categories and tags; delete (soft delete)
- Sections, entry types, fields, plugins, users and system settings are **not** exposed

### Approval workflow
- Tokens can **require approval**: changes become drafts, new entries are unpublished drafts, deletions are only requested, uploads are listed for review
- **MCP → Approvals** control panel page: word-level before/after view, Matrix blocks marked new/changed/removed, approve or reject individually or in bulk, rejection reasons, conflict warning, decision history, open-count badge
- Permission “Approve or reject changes made by the MCP Server” for non-admin editors
- `list_my_changes` lets the assistant see decisions and rejection reasons

### Safety
- Scoped tokens (`read`, `write`, `delete`), optional section restriction, expiry date, tokens stored as hashes, shown once
- All actions run with the Craft permissions of the token's user
- Matrix safety: unknown block types, unknown fields and unknown block IDs are rejected; updates that would delete existing blocks are refused unless confirmed; `append` mode; nested Matrix fields are validated
- Robust argument handling: booleans and JSON values sent as strings are interpreted correctly (`"false"` is false)
- Warnings for empty required fields in new Matrix blocks
- Audit log, rate limit (120 requests/minute/token), SSRF protection and size limit for URL uploads

### Content workflow
- Drafts can be created, read, edited and published; new content is searchable immediately
- `get_entry_type` describes fields with value formats and Matrix block types; `get_entry` can return selected fields only
- Control panel in English with a German translation
