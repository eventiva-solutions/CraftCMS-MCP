# Release Notes for MCP Server

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
