#!/usr/bin/env python3
"""
End-to-end smoke test for a running Craft site with the MCP Server plugin.

Run it ONLY against a development or staging copy – the write checks create
and delete one entry.

  MCP_URL=https://dev.example.com/mcp \
  MCP_TOKEN=cmcp_...            (scopes: read, write, delete) \
  MCP_TOKEN_READ=cmcp_...       (optional, scope: read only) \
  MCP_TEST_SECTION=blog         (optional; enables the write/delete checks) \
  python tests/integration/smoke_test.py

Only the Python standard library is needed.
"""
import json
import os
import sys
import urllib.error
import urllib.request

URL = os.environ.get("MCP_URL", "http://127.0.0.1:9191/mcp")
TOKEN = os.environ.get("MCP_TOKEN", "")
TOKEN_READ = os.environ.get("MCP_TOKEN_READ", "")
SECTION = os.environ.get("MCP_TEST_SECTION", "")

failures = 0


def check(ok, label, detail=""):
    global failures
    print(("PASS  " if ok else "FAIL  ") + label + (f"  [{detail}]" if detail and not ok else ""))
    if not ok:
        failures += 1


def post(body, token=None, raw=None, method="POST"):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(URL, data if method == "POST" else None, headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def rpc(method, params=None, token=None, rid=1):
    status, text = post({"jsonrpc": "2.0", "id": rid, "method": method, "params": params or {}}, token or TOKEN)
    try:
        return status, json.loads(text)
    except ValueError:
        return status, None


def tool(name, args=None, token=None):
    status, data = rpc("tools/call", {"name": name, "arguments": args or {}}, token)
    if not data or "result" not in data:
        return True, f"HTTP {status}: {data}"
    return data["result"]["isError"], data["result"]["content"][0]["text"]


def main():
    if not TOKEN:
        sys.exit("Set MCP_TOKEN (a token with read, write and delete scopes).")

    print(f"Testing {URL}\n")

    # --- Transport and authentication ---------------------------------------
    check(post({"jsonrpc": "2.0", "id": 1, "method": "ping"})[0] == 401, "no token -> 401")
    check(post({"jsonrpc": "2.0", "id": 1, "method": "ping"}, "cmcp_invalid")[0] == 401, "wrong token -> 401")
    check(post(None, TOKEN, method="GET")[0] == 405, "GET -> 405")
    check(post(None, TOKEN, raw=b"{not json")[0] in (200, 400), "malformed JSON handled")
    status, data = rpc("initialize", {"protocolVersion": "2025-06-18"})
    check(status == 200 and "serverInfo" in data["result"], "initialize")
    check(post({"jsonrpc": "2.0", "method": "notifications/initialized"}, TOKEN)[0] == 202, "notification -> 202")
    status, data = rpc("does/not/exist")
    check(data and data.get("error", {}).get("code") == -32601, "unknown method -> -32601")

    status, data = rpc("tools/list")
    tools = {t["name"]: t for t in data["result"]["tools"]}
    check(len(tools) >= 11, "tools/list returns tools", str(len(tools)))
    check(all("inputSchema" in t and t["description"] for t in tools.values()), "all tools have schema and description")

    # --- Read tools -----------------------------------------------------------
    for name in ("list_sites", "list_sections", "get_entry_type", "list_volumes", "list_taxonomies"):
        err, text = tool(name)
        check(not err, f"{name} works", text[:120])
    err, text = tool("get_entry_type", {"handle": "does-not-exist"})
    check(err and "Unknown entry type" in text, "get_entry_type: helpful error for unknown handle")
    err, text = tool("search_entries", {"limit": 5})
    check(not err and "results" in json.loads(text), "search_entries")
    err, text = tool("search_entries", {"orderBy": "id; DROP TABLE users"})
    check(err, "search_entries rejects bad orderBy")
    err, text = tool("get_entry", {})
    check(err and "Missing required" in text, "missing required argument is reported")
    err, text = tool("search_assets", {"limit": 3})
    check(not err, "search_assets", text[:120])

    # --- Scopes ---------------------------------------------------------------
    if TOKEN_READ:
        _, data = rpc("tools/list", token=TOKEN_READ)
        names = {t["name"] for t in data["result"]["tools"]}
        check(not any(n.startswith(("create_", "update_", "delete_", "upload_", "publish_")) for n in names),
              "read-only token sees no write/delete tools")
        err, text = tool("create_entry", {"section": SECTION or "x", "title": "x"}, TOKEN_READ)
        check(err and "scope" in text, "read-only token cannot create_entry")
        err, text = tool("delete_entry", {"id": 1}, TOKEN_READ)
        check(err and "scope" in text, "read-only token cannot delete_entry")

    # --- Write + delete round trip (creates and removes one disabled entry) --------
    if SECTION:
        err, text = tool("create_entry", {"section": SECTION, "title": "MCP smoke test entry"})
        check(not err, "create_entry", text[:200])
        if not err:
            entry = json.loads(text)
            eid = entry["id"]
            check(entry["status"] == "disabled", "new entries are disabled by default", entry["status"])
            err, text = tool("update_entry", {"id": eid, "title": "MCP smoke test entry (edited)"})
            check(not err, "update_entry", text[:200])
            err, text = tool("update_entry", {"id": eid, "fields": {"noSuchField": 1}})
            check(err and "Unknown field handle" in text, "update_entry: unknown field handle is reported")
            err, text = tool("search_entries", {"query": "smoke test entry", "status": "any"})
            check(not err and json.loads(text)["total"] >= 1, "new content is searchable immediately")
            err, text = tool("create_draft", {"id": eid, "title": "MCP smoke test entry (draft)"})
            check(not err, "create_draft", text[:200])
            err, text = tool("delete_entry", {"id": eid})
            check(not err, "delete_entry", text[:200])
            err, text = tool("get_entry", {"id": eid})
            check(err and "not found" in text, "deleted entry is gone")
    else:
        print("SKIP  write/delete checks (set MCP_TEST_SECTION to enable)")

    print(f"\n{failures} failure(s)")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
