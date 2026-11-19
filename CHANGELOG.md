# Release Notes for MCP Server

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
