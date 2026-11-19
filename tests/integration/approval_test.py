#!/usr/bin/env python3
"""
End-to-end test of the approval workflow on a running Craft site (control panel + MCP endpoint).

Run it ONLY against a development or staging copy: it creates, changes and deletes a few entries,
a tag and a file, and it approves/rejects changes in the control panel with the given user.

  MCP_URL=https://dev.example.com            site root (the MCP endpoint is MCP_URL + /mcp)
  MCP_TOKEN=cmcp_...                         token WITHOUT approval, scopes read, write, delete
  MCP_REVIEW_TOKEN=cmcp_...                  token WITH "Require approval", scopes read, write, delete
  CP_USER=admin  CP_PASSWORD=...             control panel login (admin or a user who may approve)
  MCP_TEST_SECTION=blog                      a channel section whose default fields need no values
  MCP_UPLOAD_VOLUME=uploads                  optional: a volume that accepts uploads (enables the upload checks)
  MCP_TEST_TAG_GROUP=tags                    optional: a tag group (enables the tag deletion check)
  CP_PATH=/admin                             optional: control panel path (default /admin)

Only the Python standard library is needed.
"""
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("MCP_URL", "").rstrip("/")
ADMIN_TOKEN = os.environ.get("MCP_TOKEN", "")
REVIEW_TOKEN = os.environ.get("MCP_REVIEW_TOKEN", "")
CP_USER = os.environ.get("CP_USER", "")
CP_PASSWORD = os.environ.get("CP_PASSWORD", "")
SECTION = os.environ.get("MCP_TEST_SECTION", "")
VOLUME = os.environ.get("MCP_UPLOAD_VOLUME", "")
TAG_GROUP = os.environ.get("MCP_TEST_TAG_GROUP", "")
CP_PATH = os.environ.get("CP_PATH", "/admin").rstrip("/")

PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
failures = 0


def check(ok, label, detail=""):
    global failures
    print(("PASS  " if ok else "FAIL  ") + label + (f"  [{str(detail)[:240]}]" if detail and not ok else ""))
    if not ok:
        failures += 1


class MCP:
    def __init__(self, token):
        self.token = token

    def rpc(self, method, params=None):
        req = urllib.request.Request(BASE + "/mcp", json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}).encode(),
                                     {"Authorization": "Bearer " + self.token, "Content-Type": "application/json"})
        return json.load(urllib.request.urlopen(req, timeout=180))

    def tool(self, name, args=None):
        result = self.rpc("tools/call", {"name": name, "arguments": args or {}})["result"]
        text = result["content"][0]["text"]
        try:
            return result["isError"], json.loads(text)
        except ValueError:
            return result["isError"], text

    def tool_names(self):
        return {t["name"] for t in self.rpc("tools/list")["result"]["tools"]}


class ControlPanel:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        csrf = self._csrf(self.get(CP_PATH + "/login")[1])
        self.post(CP_PATH + "/actions/users/login", {"CRAFT_CSRF_TOKEN": csrf, "loginName": CP_USER, "password": CP_PASSWORD}, json_=True)

    @staticmethod
    def _csrf(html):
        return re.search(r'name="CRAFT_CSRF_TOKEN" value="([^"]+)"', html).group(1)

    def get(self, path):
        try:
            r = self.opener.open(BASE + path, timeout=180)
            return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def post(self, path, data, json_=False):
        req = urllib.request.Request(BASE + path, urllib.parse.urlencode(data, doseq=True).encode(), {"Accept": "application/json"} if json_ else {})
        try:
            r = self.opener.open(req, timeout=180)
            return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def page(self, query=""):
        return self.get(CP_PATH + "/mcp-server/review" + query)

    def decide(self, **fields):
        csrf = self._csrf(self.page()[1])
        return self.post(CP_PATH + "/actions/mcp-server/review/decide", {"CRAFT_CSRF_TOKEN": csrf, **fields})


def changes(agent, status="all"):
    err, data = agent.tool("list_my_changes", {"status": status, "limit": 100})
    return data["changes"]


def main():
    missing = [n for n, v in [("MCP_URL", BASE), ("MCP_TOKEN", ADMIN_TOKEN), ("MCP_REVIEW_TOKEN", REVIEW_TOKEN), ("CP_USER", CP_USER),
                              ("CP_PASSWORD", CP_PASSWORD), ("MCP_TEST_SECTION", SECTION)] if not v]
    if missing:
        sys.exit("Missing environment variables: " + ", ".join(missing) + " (see the top of this file)")

    admin, agent, cp = MCP(ADMIN_TOKEN), MCP(REVIEW_TOKEN), ControlPanel()
    cleanup = []
    print(f"Testing {BASE}\n")

    # --- setup: live entries created by the token without approval -----------------------------------------------------------------------------
    err, live = admin.tool("create_entry", {"section": SECTION, "title": "Approval test live entry", "enabled": True})
    check(not err and live["status"] == "live", "setup: a live entry exists", live)
    err, victim = admin.tool("create_entry", {"section": SECTION, "title": "Approval test delete candidate", "enabled": True})
    live_id, victim_id = live["id"], victim["id"]
    cleanup += [live_id]

    # --- what a token with approval can and cannot use ------------------------------------------------------------------------------------------------
    init_review = agent.rpc("initialize", {"protocolVersion": "2025-06-18"})["result"]["instructions"]
    init_admin = admin.rpc("initialize", {"protocolVersion": "2025-06-18"})["result"]["instructions"]
    check("approval" in init_review.lower() and "list_my_changes" in init_review, "server instructions explain the approval workflow to a review token", init_review)
    check("approval" not in init_admin.lower(), "server instructions stay short for a token without approval", init_admin)

    names = agent.tool_names()
    hidden = {"publish_draft", "update_asset", "create_asset_folder", "create_term", "update_term"}
    check(not (hidden & names), "unreviewable tools are hidden from tools/list", hidden & names)
    check({"create_entry", "update_entry", "delete_entry", "list_my_changes"} <= names, "reviewable tools stay available")
    err, msg = agent.tool("publish_draft", {"draftElementId": 1})
    check(err and "require approval" in str(msg), "publish_draft is refused when called anyway", msg)

    # --- new entry: draft, not live -------------------------------------------------------------------------------------------------------------------------
    err, new = agent.tool("create_entry", {"section": SECTION, "title": "Approval test new entry"})
    check(not err and new.get("pendingApproval") is True and new.get("draftElementId"), "create_entry returns pendingApproval and a draft", new)
    new_change = new["changeId"]
    err, found = admin.tool("search_entries", {"query": "Approval test new entry", "status": "any"})
    check(found["total"] == 0, "the new entry does not exist as a real entry before approval", found["total"])

    # --- update live entry: draft only, draft is reused ----------------------------------------------------------------------------------------------------
    err, up = agent.tool("update_entry", {"id": live_id, "title": "Approval test live entry (edited)"})
    check(not err and up.get("pendingApproval") and up["draftElementId"] != live_id, "update_entry on a live entry creates a draft", up)
    update_change, draft_id = up["changeId"], up["draftElementId"]
    err, now = admin.tool("get_entry", {"id": live_id})
    check(now["title"] == "Approval test live entry", "the live entry is untouched", now["title"])
    err, again = agent.tool("update_entry", {"id": live_id, "title": "Approval test live entry (edited again)"})
    check(again.get("draftElementId") == draft_id and again.get("changeId") == update_change, "a second update reuses the same draft and queue item", again)
    err, on_draft = agent.tool("update_entry", {"id": draft_id, "title": "Approval test live entry (edited)"})
    check(not err and on_draft.get("changeId") == update_change, "the draft can be edited by its draftElementId", on_draft)
    err, draft_view = agent.tool("get_entry", {"id": draft_id})
    check(not err and draft_view.get("isDraft") is True, "get_entry can read the draft", draft_view)

    # --- deletion request: nothing is deleted ------------------------------------------------------------------------------------------------------------------
    err, deletion = agent.tool("delete_entry", {"id": victim_id})
    check(not err and deletion.get("pendingApproval") and deletion.get("deleted") is False, "delete_entry only queues the deletion", deletion)
    delete_change = deletion["changeId"]
    err, still = admin.tool("get_entry", {"id": victim_id})
    check(not err, "the entry still exists after the delete request")

    # --- optional: upload and tag deletion ------------------------------------------------------------------------------------------------------------------------
    upload_change = asset_id = tag_id = tag_change = None
    if VOLUME:
        err, up_asset = agent.tool("upload_asset", {"volume": VOLUME, "filename": "approval-test.png", "data": PNG})
        check(not err and up_asset.get("pendingApproval"), "upload_asset uploads and queues the file", up_asset)
        if not err:
            asset_id, upload_change = up_asset["id"], up_asset["changeId"]
    if TAG_GROUP:
        err, tag = admin.tool("create_term", {"type": "tag", "group": TAG_GROUP, "title": "Approval test tag"})
        tag_id = tag["id"]
        err, tag_delete = agent.tool("delete_term", {"type": "tag", "id": tag_id})
        check(not err and tag_delete.get("pendingApproval") and tag_delete.get("deleted") is False, "delete_term only queues the deletion", tag_delete)
        tag_change = tag_delete["changeId"]

    pending = changes(agent, "pending")
    check(len(pending) >= 3, "list_my_changes shows the pending proposals", len(pending))

    # --- the approvals page ------------------------------------------------------------------------------------------------------------------------------------------------
    status, page = cp.page()
    check(status == 200, "the approvals page loads", status)
    check(all(s in page for s in ["Approval test new entry", "Approval test live entry", "Approval test delete candidate"]), "all proposals are listed")
    check("<ins>" in page, "the before/after view marks inserted text")

    # Enter in a text field submits the hidden no-op button: nothing may change
    count_before = len(changes(agent, "pending"))
    cp.decide(decision="none", **{"ids[]": [str(update_change), str(delete_change)]})
    cp.decide(decision="approve")  # nothing selected
    check(len(changes(agent, "pending")) == count_before, "decision=none and an empty selection change nothing")

    # --- reject with a reason ---------------------------------------------------------------------------------------------------------------------------------------------------
    cp.decide(single=f"reject:{update_change}", **{f"notes[{update_change}]": "Please keep the title factual."})
    rejected = [c for c in changes(agent, "rejected") if c["changeId"] == update_change]
    check(rejected and rejected[0].get("reason") == "Please keep the title factual.", "the rejection reason is visible to the assistant", rejected)
    err, now = admin.tool("get_entry", {"id": live_id})
    check(now["title"] == "Approval test live entry", "the rejected change never went live", now["title"])

    # --- bulk approve ----------------------------------------------------------------------------------------------------------------------------------------------------------------------
    cp.decide(decision="approve", **{"ids[]": [str(new_change), str(delete_change)]})  # the form posts ids[]
    err, found = admin.tool("search_entries", {"query": "Approval test new entry", "status": "any"})
    check(found["total"] == 1, "the approved new entry now exists", found)
    if found["total"]:
        cleanup.append(found["results"][0]["id"])
        check(found["results"][0]["status"] == "disabled", "it keeps the requested disabled state", found["results"][0]["status"])
    err, gone = admin.tool("get_entry", {"id": victim_id})
    check(err and "not found" in str(gone), "the approved deletion removed the entry")

    if upload_change:
        cp.decide(single=f"reject:{upload_change}", **{f"notes[{upload_change}]": "Wrong image."})
        err, asset = admin.tool("get_asset", {"id": asset_id})
        check(err, "a rejected upload is deleted again", asset)
    if tag_change:
        cp.decide(single=f"approve:{tag_change}")
        err, tags = admin.tool("search_terms", {"type": "tag", "group": TAG_GROUP, "query": "Approval test tag"})
        check(not any(t["id"] == tag_id for t in tags), "the approved tag deletion removed the tag")

    # --- second update, approved -------------------------------------------------------------------------------------------------------------------------------------------------
    err, second = agent.tool("update_entry", {"id": live_id, "title": "Approval test live entry (approved)"})
    cp.decide(single=f"approve:{second['changeId']}")
    err, now = admin.tool("get_entry", {"id": live_id})
    check(now["title"] == "Approval test live entry (approved)", "an approved change is live", now["title"])
    still_open = changes(agent, "pending")
    check(len(still_open) == 0, "no proposals are left open", still_open)
    check(len(changes(agent, "approved")) >= 3, "the assistant sees the approved items")

    # --- history ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    status, history = cp.page("?status=decided")
    check("Please keep the title factual." in history, "the decided tab shows the rejection reason")
    status, again = cp.decide(single=f"approve:{new_change}")
    check(all(c["status"] == "approved" for c in changes(agent, "approved") if c["changeId"] == new_change), "an already decided item stays as it is")

    # --- cleanup ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    for entry_id in cleanup:
        admin.tool("delete_entry", {"id": entry_id})

    print(f"\n{failures} failure(s)")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
