# MCP Server for Craft CMS

Let AI assistants such as **Claude** and **Codex** maintain your Craft CMS content through the [Model Context Protocol](https://modelcontextprotocol.io) – with scoped API tokens, Craft permissions and a full audit log.

The assistant can search and read entries, create and edit entries and drafts, upload and organize assets, and manage categories and tags. It can **not** change your site's configuration: sections, entry types, fields, plugins, users, project config and system settings are out of reach (sections and fields are read-only so the assistant knows how your content is structured).

> German documentation: [README.de.md](README.de.md)

## Requirements
- Craft CMS 5.0 or later (tested with 5.11)
- PHP 8.2 or later (tested with 8.4)
- A public or private HTTPS URL for the Craft site (the MCP endpoint is `POST /mcp`)

## Installation
```bash
composer require eventiva/craft-mcp
php craft plugin/install mcp-server
```
Or install it from the Craft Plugin Store. The plugin handle is `mcp-server`. It can be installed next to `stimmt/craft-mcp` (handle `mcp`); the two plugins use separate tables and routes.

## Setup
1. In the control panel open **MCP → Tokens** and create a token:
   - the Craft **user** whose permissions the token acts with (recommended: a dedicated user with minimal permissions),
   - **scopes**: `read` is always on; add `write` and/or `delete` only if needed,
   - **Require approval** (recommended): changes only go live after a person approves them, see [Approval workflow](#approval-workflow-recommended),
   - optionally limit the token to certain **sections** and set an **expiry date**.
2. Copy the token – it is shown **once**. The page also shows ready-made commands for your client.

**Claude Code**
```bash
claude mcp add --transport http craft https://example.com/mcp \
  --header "Authorization: Bearer cmcp_…"
```

**Codex** (`~/.codex/config.toml`)
```toml
[mcp_servers.craft]
url = "https://example.com/mcp"
http_headers = { Authorization = "Bearer cmcp_…" }
```

If your host strips the `Authorization` header, send the token in an `X-Mcp-Token` header instead.

## What the assistant can do

| Scope | Tools |
|---|---|
| `read` | `list_sites`, `list_sections`, `get_entry_type`, `search_entries`, `get_entry`, `list_volumes`, `list_asset_folders`, `search_assets`, `get_asset`, `list_taxonomies`, `search_terms`, `list_my_changes` |
| `write` | `create_entry`, `update_entry`, `create_draft`, `publish_draft`, `upload_asset`, `update_asset`, `create_asset_folder`, `create_term`, `update_term` |
| `delete` | `delete_entry`, `delete_asset`, `delete_term` (soft delete, restorable in the control panel) |

A good workflow for the assistant: `list_sections` → `get_entry_type` (field handles and formats) → `search_entries` / `get_entry` → `create_entry` / `update_entry`, or `create_draft` for changes to live content.

Highlights:
- Works with any field type Craft can serialize: plain text, rich text, dropdowns, dates, tables, relations, Matrix, assets, categories, tags.
- Helpful errors: wrong field handles, validation problems or missing permissions are returned in plain language so the assistant can correct itself.
- `get_entry` can return only selected fields (`fields`) and shortens huge SEO bundles by default to save context.
- New entries are created **disabled** by default. Changes to live content are best made through drafts.
- Content created or changed through MCP is indexed for search immediately.

## Approval workflow (recommended)
Create tokens with **Require approval** (on by default in the form). The server then enforces that **nothing changes live without a human decision**, no matter what the AI client tries:

- **Entries:** changes to live entries are saved in a Craft **draft**; new entries are saved as unpublished drafts and stay disabled until approved. Editing the same entry again reuses the same draft.
- **Deletions** (entries, assets, categories, tags) are only **requested**. Nothing is deleted until you approve.
- **Uploads** are uploaded (the assistant needs the asset ID), but listed for review. Rejecting deletes the file again.
- Tools that cannot be reviewed are hidden and refused for such tokens: `publish_draft`, `update_asset`, `create_asset_folder`, `create_term`, `update_term`.

Review everything in **MCP → Approvals** (the menu item shows the number of open proposals):
- a clear **before/after view**: word-level text diff, Matrix blocks marked as new, changed or removed, thumbnails for uploads;
- **approve or reject** each proposal, or tick several and act on them in bulk; a rejection can carry a **reason**;
- a warning if the live entry was changed after the draft was created;
- a **history** of all decisions, and every decision is written to the audit log.

The assistant sees your decisions with `list_my_changes`, including rejection reasons, and can revise its work. Editors who are not admins need the permission **Approve or reject changes made by the MCP Server** to see the page.

## Matrix fields (nested blocks)
Craft silently drops unknown block types and **deletes every block that is not sent again** when a Matrix field is saved. The plugin protects you from both:

- `get_entry_type` lists every Matrix field's allowed **block types** and the fields of each block type (nested Matrix fields included).
- Unknown block types, unknown field handles inside a block and unknown block IDs are **rejected with a clear error** that lists the allowed values. Nothing is changed.
- A call that would **delete existing blocks** is refused and names the blocks that would be lost. Send them again with their `id`, add blocks with `{"mode": "append", "blocks": [...]}`, or confirm the removal with `allowBlockRemoval: true`.
- Sending back the Matrix value exactly as `get_entry` returned it changes nothing.
- Write and update responses include the resulting block count per Matrix field (`"blocks": {"faq": 3}`).
- If a new block leaves a **required** field empty, the response contains a `warnings` entry. Craft only enforces required fields once the entry is enabled, so such an entry cannot be enabled or published until the field is filled.

```jsonc
// add one block, keep all existing ones
{"id": 123, "fields": {"faq": {"mode": "append", "blocks": [
  {"type": "faq", "title": "New question", "fields": {"answer": "..."}}
]}}}

// edit one block, keep the others, add a new one
{"id": 123, "fields": {"faq": [
  {"id": 501, "fields": {"answer": "Updated"}},
  {"id": 502},
  {"type": "faq", "title": "New question", "fields": {"answer": "..."}}
]}}
```

## Drafts
`create_draft` returns a `draftElementId`. `get_entry` and `update_entry` accept that ID, so a draft can be reviewed and refined before `publish_draft` applies it. Drafts have their own block IDs, read them with `get_entry` on the draft.

## Security
- Tokens are stored only as SHA-256 hashes and are never shown again.
- Every action runs with the Craft permissions of the token's user, on top of the token scopes.
- Optional restriction to specific sections; optional expiry date; revoke any time.
- Rate limit: 120 requests per minute per token (HTTP 429).
- Every tool call is written to the audit log (control panel, retained for 90 days; large values are shortened).
- Uploads by URL accept only `http(s)`, refuse private and reserved addresses (SSRF protection), do not follow redirects and are limited to 20 MB.
- Deleting is a soft delete.
- Use HTTPS only. See [SECURITY.md](SECURITY.md) for recommendations and how to report vulnerabilities.

## Notes and limitations
- Authentication is by bearer token (no OAuth login flow yet). The transport is Streamable HTTP (JSON responses, no SSE stream); `GET /mcp` answers `405`.
- Asset uploads go to the configured volume (including S3 / object storage). When testing on a copy of production data, make sure the volumes do not point to the real storage.
- The control panel pages are in English; a German translation is included and used automatically for users whose control panel language is German.
- Search relies on Craft's search index. Fields marked as not searchable are not found by `search_entries`.
- The plugin does not modify sections, fields, entry types, users, plugins or system settings, and never will.

## Troubleshooting
| Symptom | Likely cause |
|---|---|
| `401 Invalid or missing token` | Wrong, expired or revoked token; some hosts strip `Authorization` – use `X-Mcp-Token`. |
| `Token lacks the 'write' scope…` | Create a token with the needed scope. |
| `This token has no access to section …` | The token is limited to other sections. |
| `429` | More than 120 requests/minute with one token. |
| Client shows "connecting…" | Check that `https://your-site/mcp` is reachable and answers `POST` with JSON; check the audit log. |

## Development and tests
```bash
# Unit tests (tool catalogue, scopes, schemas, translations, diff) – needs a Craft 5 project's vendor directory
CRAFT_VENDOR_PATH=/path/to/project/vendor vendor/bin/phpunit -c phpunit.xml

# End-to-end smoke test against a DEV/STAGING site (creates and deletes one entry)
MCP_URL=https://dev.example.com/mcp MCP_TOKEN=cmcp_… MCP_TOKEN_READ=cmcp_… MCP_TEST_SECTION=blog   python tests/integration/smoke_test.py
```

## Support
Issues and questions: https://github.com/eventiva-solutions/CraftCMS-MCP/issues

## License
This is a commercial plugin released under the [Craft License](LICENSE.md). One license per production site.
