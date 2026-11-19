# MCP Server for Craft CMS

Verbindet LLM-Clients (Claude, Codex, …) per [Model Context Protocol](https://modelcontextprotocol.io) mit Craft CMS, um Inhalte zu pflegen: Einträge, Assets, Suche. Keine Verwaltungseinstellungen (Sections, Felder, Plugins, Benutzer, System).

## Status
`0.2.0` – Token-Verwaltung, Audit-Log, Rate-Limit, Lese-, Schreib- und Lösch-Tools. Noch nicht gegen eine Live-Craft-Instanz getestet.

## Installation
```bash
composer require eventiva/craft-mcp
php craft plugin/install mcp-server
```

## Einrichtung
1. CP → **MCP → Tokens** (`/admin/mcp-server`) → Token anlegen (Craft-Benutzer, Rechte `write`/`delete`, optional Sections, Ablaufdatum).
2. Token wird einmalig angezeigt, zusammen mit fertigen Befehlen:

```bash
claude mcp add --transport http craft https://example.com/mcp \
  --header "Authorization: Bearer cmcp_…"
```

```toml
# ~/.codex/config.toml
[mcp_servers.craft]
url = "https://example.com/mcp"
http_headers = { Authorization = "Bearer cmcp_…" }
```

Entfernt dein Hoster den `Authorization`-Header, nutze stattdessen den Header `X-Mcp-Token: cmcp_…`.

## Sicherheit
- Tokens werden nur als SHA-256-Hash gespeichert.
- Alle Aktionen laufen mit den Craft-Rechten des zugeordneten Benutzers.
- Scopes: `read` (immer), `write`, `delete` (Standard aus). Optionale Section-Einschränkung.
- Jeder Tool-Aufruf landet im Audit-Log (90 Tage).

## Tools
| Scope | Tools |
|---|---|
| `read` | `list_sites`, `list_sections`, `get_entry_type`, `search_entries`, `get_entry`, `list_volumes`, `list_asset_folders`, `search_assets`, `get_asset`, `list_taxonomies`, `search_terms` |
| `write` | `create_entry`, `update_entry`, `create_draft`, `publish_draft`, `upload_asset`, `update_asset`, `create_asset_folder`, `create_term`, `update_term` |
| `delete` | `delete_entry`, `delete_asset`, `delete_term` (Soft-Delete) |

Neue Einträge werden standardmäßig deaktiviert angelegt. Rate-Limit: 120 Requests/Minute/Token.
