# Plugin Store listing (draft)

Copy these texts into the Craft Console when you create the plugin. Everything is in English because the Plugin Store is English-only.

## Name
MCP Server

## Summary (one line)
Let Claude, Codex and other AI assistants maintain your Craft content – safely, with scoped tokens and an audit log.

## Pricing (to be set in the Craft Console)
- Single edition **Standard**: 19 one-time license (includes one year of updates), renewal 9 per year.
- Currency: set in the console (the store shows prices in USD – decide whether 19/9 means USD or EUR).

## Categories / keywords (suggestions)
AI, Content management, Developer tools · mcp, model context protocol, ai, llm, claude, codex, content editing, automation

## Description

**Let your AI assistant do the content work – without giving it the keys to your site.**

MCP Server connects Craft CMS to AI assistants such as Claude and Codex through the open Model Context Protocol (MCP). Ask your assistant to find entries, rewrite texts, create drafts, upload images with alt text or tidy up categories and tags – and let it work directly in Craft.

Unlike a plain database or console bridge, MCP Server is built for **content**, and keeps your configuration out of reach: sections, fields, entry types, plugins, users and system settings cannot be changed through it.

### What your assistant can do
- **Find and read:** full-text search over entries, assets, categories and tags; read sections, entry types and field definitions so the assistant knows how your content is built.
- **Create and edit:** create entries, update any field (plain text, rich text, dropdowns, dates, tables, relations, Matrix, assets, categories, tags), work with drafts and publish them.
- **Manage media:** upload files from a URL or base64, set titles and alt text, rename, move, create folders.
- **Organize:** create and update categories and tags.
- **Clean up (optional):** soft-delete entries, assets, categories and tags – only with a token that has the `delete` scope.

### Built for safe use
- **Scoped tokens:** `read` is always on, `write` and `delete` are opt-in per token.
- **Craft permissions apply:** every token acts as a Craft user, so it can only do what that user may do.
- **Limit by section** and set an **expiry date** per token; revoke tokens at any time.
- **Audit log** of every tool call in the control panel.
- **Rate limiting** per token and **SSRF protection** for URL uploads.
- **Safe with Matrix and nested content:** unknown block types are rejected, and an update can never silently delete existing blocks.
- New entries are created **disabled**, and changes to live content can go through **drafts**.
- Tokens are stored only as hashes and shown once.

### Works with your tools
Connect Claude Code (and other MCP clients that support HTTP servers with an Authorization header) with a single command that the plugin shows you right after you create a token. The plugin can be installed next to other MCP plugins.

### Good to know
- Authentication is by bearer token; there is no OAuth login flow yet.
- Search uses Craft's search index; content created through MCP is indexed immediately.
- Tested with Craft 5.11 and PHP 8.4 on MariaDB 11.4.

## Quick start
1. Install the plugin and open **MCP → Tokens** in the control panel.
2. Create a token (choose a Craft user, the scopes and optionally sections).
3. Copy the token and the connect command shown on the page.
4. Ask your assistant: *"List the sections of my site and show me the fields of the News entry type."*

## FAQ

**Can the assistant change my site structure?**
No. Sections, entry types, fields, plugins, users, project config and system settings are not exposed. Sections and fields can only be read.

**Can it delete content?**
Only if you give the token the `delete` scope, and deletion is a soft delete that you can restore from the trash.

**Will it publish things by itself?**
New entries are created disabled. For changes to live content the assistant can create a draft that you review, or – if you allow `write` – publish it.

**Which user does the assistant act as?**
The Craft user you assign to the token. We recommend a dedicated user with minimal permissions.

**Does it work with S3 / cloud storage volumes?**
Yes, uploads go through Craft's normal asset and filesystem layer.

**Is there a free version?**
Craft lets you try plugins in development; a license is required for production.

**Does it work together with other MCP plugins?**
Yes, it has its own handle (`mcp-server`), tables and routes.

## Screenshots to take
1. **Tokens page** with the "new token" box (blur the token) – shows the ready-made Claude Code / Codex commands.
2. **Create token form** (user, scopes, sections, expiry).
3. **Audit log** with a few successful and one denied call.
4. A **client conversation** (e.g. Claude Code listing sections, then creating a draft).
5. The **created entry** in the control panel.

## Release notes (first release)
First release: MCP endpoint with scoped tokens, 23 tools for content, assets, categories and tags, audit log, rate limiting.

## Support text
For questions and bug reports, please open an issue at https://github.com/eventiva-solutions/CraftCMS-MCP/issues.
