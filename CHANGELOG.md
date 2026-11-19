# Release Notes for MCP Server

## 0.5.0 - 2026-11-19
- **Matrix safety:** unknown block types, unknown field handles in blocks and unknown block IDs are rejected with the list of allowed values (Craft silently drops them). Updates that would delete existing blocks are refused unless `allowBlockRemoval=true`; `{"mode": "append"}` adds blocks without touching existing ones. Nested Matrix fields are validated as well.
- `get_entry_type` lists block types (and their fields) of Matrix fields and adds a `valueFormat` hint to fields (e.g. "HTML string (rich text)", "array of element IDs")
- `get_entry` and `update_entry` accept the `draftElementId` of a draft; entry summaries show `isDraft` / `canonicalId`
- Write responses include the resulting block count per Matrix field

## 0.4.0 - 2026-11-19
- Added Craft License (`LICENSE.md`), English `README.md`, `SECURITY.md` and Plugin Store texts (`docs/store-listing.md`)
- Added unit tests (tool catalogue, scopes, schemas) and an end-to-end smoke test script

## 0.3.1 - 2026-11-19
- Fix: `get_entry_type` with a handle failed on Craft 5.11 (`getIsTranslatable()` needs an argument)
- Content created or changed through MCP is indexed for search immediately (no queue run needed)
- `get_entry`: new `fields` parameter; big SEO bundles (e.g. SEOmatic) are shortened by default
- `search_entries` no longer returns nested (Matrix) entries
- `update_asset`: an empty alt text resets the value to `null`

## 0.3.0 - 2026-11-19
- Plugin handle is now `mcp-server`, tables are `mcpserver_tokens` / `mcpserver_audit` (no clash with `stimmt/craft-mcp`)
- Fix: `update_asset` failed when only changing metadata; rename/move now uses the Assets service
- Fix: rate limit is atomic (mutex), parallel requests can no longer bypass it
- Token expiry is set to the end of the chosen day
- Tested against Craft 5.11 / PHP 8.4 / MariaDB 11.4

## 0.2.0
- Write tools: entries, drafts, assets (upload by URL/base64), asset folders, categories/tags
- Delete tools (scope `delete`)
- Rate limit per token, SSRF protection for URL uploads

## 0.1.0
- Initial scaffold: Streamable-HTTP endpoint `/mcp`, scoped tokens, audit log
- Read tools: sites, sections, entry types, entries, volumes, assets
