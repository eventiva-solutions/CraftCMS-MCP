# MCP Server for Craft CMS

Verbindet LLM-Clients (Claude, Codex, …) per [Model Context Protocol](https://modelcontextprotocol.io) mit Craft CMS, um Inhalte zu pflegen: Einträge, Assets, Suche. Keine Verwaltungseinstellungen (Sections, Felder, Plugins, Benutzer, System).

## Status
`0.1.0` – Phase 1: Gerüst, Token-Verwaltung, Audit-Log, Lese-Tools. Schreib- und Lösch-Tools folgen.

## Installation
```bash
composer require eventiva/craft-mcp
php craft plugin/install mcp
```

## Einrichtung
1. CP → **MCP → Tokens** → Token anlegen (Craft-Benutzer, Rechte `write`/`delete`, optional Sections, Ablaufdatum).
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

## Tools (aktuell)
`list_sites`, `list_sections`, `get_entry_type`, `search_entries`, `get_entry`, `list_volumes`, `search_assets`, `get_asset`

## Geplant
Entries anlegen/ändern/verschieben/Drafts, Asset-Upload, Kategorien/Tags, Löschen (Scope `delete`), Rate-Limit, optional stdio.
