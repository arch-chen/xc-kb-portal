#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
知识库 API 客户端（零依赖，只用标准库）。

本地 Agent 调知识库就三个动作：
    查  search()      —— 先看看库里有没有
    写  push()        —— 有就更新、没有就新增（服务端按问题指纹判重，重复推不会堆）
    核  versions()    —— 答案变过的条目留着旧版本，可以人工复核

用法：
    from agent_client import KB
    kb = KB("https://kb.example.com", "kb_xxxxxxxx")
    print(kb.me())
    print(kb.search("库存清零", limit=3))
    print(kb.push([{"question": "...", "body": "...", "source": "群聊"}]))

环境变量 KB_URL / KB_KEY 也可以直接用。
"""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Iterable


class KBError(RuntimeError):
    def __init__(self, code: str, message: str, status: int = 0):
        super().__init__(f"[{code}] {message}")
        self.code = code
        self.message = message
        self.status = status


class KB:
    def __init__(self, base_url: str | None = None, key: str | None = None, timeout: int = 30):
        self.base_url = (base_url or os.environ.get("KB_URL", "")).rstrip("/")
        self.key = key or os.environ.get("KB_KEY", "")
        self.timeout = timeout
        if not self.base_url:
            raise ValueError("缺少 base_url（或环境变量 KB_URL）")
        if not self.key:
            raise ValueError("缺少 API Key（或环境变量 KB_KEY）")

    # ---------------- 底层 ----------------

    def _call(self, method: str, path: str, query: dict | None = None,
              body: Any = None) -> dict:
        url = f"{self.base_url}/api.php"
        params = {"path": path}
        if query:
            params.update({k: v for k, v in query.items() if v is not None and v != ""})
        url += "?" + urllib.parse.urlencode(params)

        data = None
        headers = {"Authorization": f"Bearer {self.key}", "Accept": "application/json"}
        if body is not None:
            data = json.dumps(body, ensure_ascii=False).encode("utf-8")
            headers["Content-Type"] = "application/json"

        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8", "replace")
            try:
                err = json.loads(raw).get("error", {})
            except Exception:
                err = {}
            raise KBError(err.get("code", "http_error"),
                          err.get("message", raw[:200] or str(e)), e.code) from None

        if not payload.get("ok"):
            err = payload.get("error", {})
            raise KBError(err.get("code", "unknown"), err.get("message", ""))
        return payload

    # ---------------- 读 ----------------

    def me(self) -> dict:
        """我是谁：Key 绑定的分组、读写能力、条目数。启动时先调一次。"""
        return self._call("GET", "/v1/me")["data"]

    def search(self, q: str, limit: int = 5, offset: int = 0, kind: str = "") -> list[dict]:
        """中文检索。返回带 score 和 snippet 的条目列表，看 snippet 就能判断相关性。"""
        res = self._call("GET", "/v1/search",
                         {"q": q, "limit": limit, "offset": offset, "kind": kind})
        return res["data"]

    def search_long(self, q: str, limit: int = 5) -> list[dict]:
        """长问题（整段买家咨询）走 POST，避免 URL 过长。"""
        return self._call("POST", "/v1/search", body={"q": q, "limit": limit})["data"]

    def entry(self, entry_id: int) -> dict:
        return self._call("GET", f"/v1/entries/{entry_id}")["data"]

    def versions(self, entry_id: int) -> list[dict]:
        """版本历史，最后一条是当前版本（is_current=true）。"""
        return self._call("GET", f"/v1/entries/{entry_id}/versions")["data"]

    def changes(self, since: str = "", limit: int = 200) -> dict:
        """增量拉取：since（YYYY-MM-DD HH:MM:SS）之后被改动的条目，含删除标记。"""
        res = self._call("GET", "/v1/changes", {"since": since, "limit": limit})
        return {"items": res["data"], "meta": res["meta"]}

    def export_all(self, kind: str = "", page: int = 200) -> Iterable[dict]:
        """全量导出，逐条 yield，用来在本地建缓存或喂给检索。"""
        after = 0
        while True:
            res = self._call("GET", "/v1/export", {"after": after, "limit": page, "kind": kind})
            for it in res["data"]:
                yield it
            if not res["meta"]["has_more"]:
                break
            after = res["meta"]["next_after"]

    # ---------------- 写 ----------------

    def push(self, items: list[dict], strict: bool = False) -> dict:
        """
        幂等写入（推荐）。

        每条 item: {"question": ..., "body": ..., "source": "群聊", "source_date": "2026-11-18"}
        返回 {"created": n, "updated": n, "skipped": n, "results": [...]}

        - created：新问题
        - skipped：库里已有同样问题且答案没变（重复推的噪音都落在这里）
        - updated：同一问题答案变了，服务端会先把旧答案存成历史版本

        strict=True 时遇到 errors 直接抛异常，否则原样返回（附带 errors 列表）。
        """
        if not items:
            return {"created": 0, "updated": 0, "skipped": 0, "results": []}
        if len(items) > 200:
            raise ValueError("单次最多 200 条，请自行分批")

        res = self._call("POST", "/v1/entries", body={"items": items, "mode": "upsert"})
        data = res["data"]
        if strict and res.get("errors"):
            raise KBError("partial_failure", json.dumps(res["errors"], ensure_ascii=False))
        return data

    def update(self, entry_id: int, **fields) -> dict:
        """精确修改某条（已知 id 时用）。version 会自动 +1，旧值进版本历史。"""
        return self._call("PUT", f"/v1/entries/{entry_id}", body=fields)["data"]

    def delete(self, entry_id: int) -> dict:
        """软删除。"""
        return self._call("DELETE", f"/v1/entries/{entry_id}")["data"]


if __name__ == "__main__":
    import sys

    kb = KB()
    info = kb.me()
    print(json.dumps(info, ensure_ascii=False, indent=2))

    if len(sys.argv) > 1:
        for row in kb.search_long(" ".join(sys.argv[1:])):
            print(f"\n[{row['id']}] v{row['version']} score={row['score']}")
            print("Q:", row["question"])
            print("A:", row["snippet"])
