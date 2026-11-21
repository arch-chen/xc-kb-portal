#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把「问答总表」CSV 推进知识库（本地 Agent 每天的增量同步就是这一步）。

    python push_qa_csv.py --url https://kb.example.com --key kb_xxx \
        --csv /path/to/问答总表.csv --source 群聊

特点：
- 幂等：重复跑不会堆数据，答案没变的会落在 skipped
- 分批：每批 100 条，失败只影响这一批
- 兼容列名：问题/提问/question、当前答案/答案/body、首次提问/日期、分册

第一次跑建议加 --dry-run 看看数量对不对。
"""

from __future__ import annotations

import argparse
import csv
import sys
import time

from agent_client import KB, KBError

PICK_Q = ("问题", "提问", "question")
PICK_A = ("当前答案", "答案", "answer", "body")
PICK_DATE = ("首次提问", "日期", "source_date")
PICK_BOOK = ("分册", "分类", "book")


def pick(row: dict, names) -> str:
    for n in names:
        if n in row and row[n] is not None:
            return str(row[n]).strip()
    return ""


def load_rows(path: str, source: str) -> list[dict]:
    items = []
    with open(path, "r", encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            q = pick(row, PICK_Q)
            a = pick(row, PICK_A)
            if not q and not a:
                continue
            book = pick(row, PICK_BOOK)
            items.append({
                "question": q,
                "body": a,
                "source": f"{source}·{book}" if book else source,
                "source_date": pick(row, PICK_DATE)[:10],
            })
    return items


def main() -> int:
    ap = argparse.ArgumentParser(description="推送问答 CSV 到知识库")
    ap.add_argument("--url", required=True, help="站点根地址，如 https://kb.example.com")
    ap.add_argument("--key", required=True, help="API Key（kb_ 开头）")
    ap.add_argument("--csv", required=True, help="问答总表 CSV 路径")
    ap.add_argument("--source", default="群聊", help="来源标签，默认「群聊」")
    ap.add_argument("--batch", type=int, default=100, help="每批条数，上限 200")
    ap.add_argument("--dry-run", action="store_true", help="只统计不写入")
    args = ap.parse_args()

    kb = KB(args.url, args.key)
    info = kb.me()
    print(f"目标：{info['group']['name']}｜写权限 {info['scopes']['write']}｜现有 {info['entries']} 条")
    if not info["scopes"]["write"]:
        print("这个 Key 是只读的，无法推送")
        return 2

    rows = load_rows(args.csv, args.source)
    print(f"读到 {len(rows)} 条问答")
    if args.dry_run:
        return 0

    total = {"created": 0, "updated": 0, "skipped": 0}
    batch = max(1, min(200, args.batch))
    t0 = time.time()

    for i in range(0, len(rows), batch):
        chunk = rows[i:i + batch]
        try:
            r = kb.push(chunk)
        except KBError as e:
            print(f"  第 {i // batch + 1} 批失败：{e}")
            continue
        for k in total:
            total[k] += r.get(k, 0)
        print(f"  第 {i // batch + 1} 批：新增 {r.get('created', 0)}｜更新 {r.get('updated', 0)}"
              f"｜跳过 {r.get('skipped', 0)}")

    print(f"\n完成，用时 {time.time() - t0:.1f}s")
    print(f"新增 {total['created']}｜更新 {total['updated']}｜跳过 {total['skipped']}")
    if total["updated"]:
        print("有答案被更新的条目已留旧版本，可在后台问答详情页复核")
    return 0


if __name__ == "__main__":
    sys.exit(main())
