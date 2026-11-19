# MCP Server for Craft CMS (Deutsch)

> Die vollständige, aktuelle Dokumentation (Englisch): [README.md](README.md)

Verbindet LLM-Clients (Claude, Codex, …) per [Model Context Protocol](https://modelcontextprotocol.io) mit Craft CMS, um Inhalte zu pflegen: Einträge, Entwürfe, Assets, Kategorien und Tags, dazu eine Suche. **Keine Verwaltungseinstellungen**: Sections, Entry Types, Felder, Plugins, Benutzer und System bleiben unerreichbar. Sections und Felder können nur gelesen werden.

## Installation
```bash
composer require eventiva/craft-mcp
php craft plugin/install mcp-server
```
Handle: `mcp-server`. Das Plugin kann neben `stimmt/craft-mcp` (Handle `mcp`) installiert sein, es nutzt eigene Tabellen (`mcpserver_tokens`, `mcpserver_audit`).

## Einrichtung
1. CP → **MCP → Tokens** (`/admin/mcp-server`) → Token anlegen: Craft-Benutzer, Rechte `write` / `delete`, optional Sections, Ablaufdatum.
2. Der Token wird **einmalig** angezeigt, zusammen mit fertigen Befehlen:

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

Entfernt dein Hoster den `Authorization`-Header, nutze stattdessen den Header `X-Mcp-Token: cmcp_…`. Betreibe den Endpoint nur über HTTPS.

## Sicherheit
- Tokens werden nur als SHA-256-Hash gespeichert und nach dem Anlegen nie wieder angezeigt.
- Alle Aktionen laufen mit den Craft-Rechten des zugeordneten Benutzers. Empfohlen: ein eigener Benutzer mit minimalen Rechten.
- Scopes: `read` (immer), `write`, `delete` (Standard aus). Optionale Beschränkung auf bestimmte Sections.
- Rate-Limit: 120 Requests pro Minute und Token (HTTP 429).
- Jeder Tool-Aufruf landet im Audit-Log (CP → MCP → Audit-Log, 90 Tage Aufbewahrung). Große Werte werden gekürzt.
- Datei-Upload per URL: nur http(s), keine privaten oder reservierten Adressen (SSRF-Schutz), keine Redirects, max. 20 MB.
- Neue Einträge werden standardmäßig **deaktiviert** angelegt (`enabled=false`).
- Löschen ist ein Soft-Delete und im CP wiederherstellbar.

## Tools
| Scope | Tools |
|---|---|
| `read` | `list_sites`, `list_sections`, `get_entry_type`, `search_entries`, `get_entry`, `list_volumes`, `list_asset_folders`, `search_assets`, `get_asset`, `list_taxonomies`, `search_terms` |
| `write` | `create_entry`, `update_entry`, `create_draft`, `publish_draft`, `upload_asset`, `update_asset`, `create_asset_folder`, `create_term`, `update_term` |
| `delete` | `delete_entry`, `delete_asset`, `delete_term` |

Empfohlener Ablauf für das Modell: `list_sections` → `get_entry_type` (Feld-Handles und Formate) → `search_entries` / `get_entry` → `create_entry` / `update_entry` oder `create_draft`.

## Hinweise
- **Suche:** Craft aktualisiert den Suchindex über die Queue. Neue oder geänderte Inhalte sind erst nach dem nächsten Queue-Lauf per Suche auffindbar (wie im CP).
- **Asset-Uploads** gehen in das konfigurierte Volume (auch S3/Object Storage). Beim Testen auf Kopien der Live-Daten darauf achten, dass die Volumes nicht auf den echten Speicher zeigen.
- Entwürfe (`create_draft`) sind die sicherere Wahl für Änderungen an live geschalteten Inhalten.

## Lizenz
Kommerzielles Plugin unter der [Craft License](LICENSE.md).
