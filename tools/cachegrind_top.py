#!/usr/bin/env python3
"""Summarise an Xdebug cachegrind profile: top self-time functions and the
heaviest call paths (greedy descent by inclusive time from {main}).

    python3 tools/cachegrind_top.py cachegrind.out.123[.gz] [--top 25] [--paths 3] [--root SUBSTR]
"""

from __future__ import annotations

import argparse
import gzip
import os
import pickle
import re
from collections import defaultdict


def parse(path: str):
    opener = gzip.open if path.endswith(".gz") else open
    names: dict[str, str] = {}
    files: dict[str, str] = {}
    self_cost: dict[str, int] = defaultdict(int)
    calls: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))  # caller -> callee -> incl
    call_counts: dict[str, int] = defaultdict(int)
    cur_fn = None
    pending_callee = None

    def resolve(table, raw):
        m = re.match(r"\((\d+)\)(?: (.*))?", raw)
        if not m:
            return raw
        key, name = m.groups()
        if name is not None:
            table[key] = name
            return name
        return table.get(key, raw)

    with opener(path, "rt", errors="replace") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if not line:
                continue
            if line.startswith("fl=") or line.startswith("fi=") or line.startswith("fe="):
                resolve(files, line[3:])
            elif line.startswith("fn="):
                cur_fn = resolve(names, line[3:])
            elif line.startswith("cfl=") or line.startswith("cfi="):
                resolve(files, line[4:])
            elif line.startswith("cfn="):
                pending_callee = resolve(names, line[4:])
            elif line.startswith("calls="):
                call_counts[pending_callee] += int(line[6:].split()[0])
                pending_callee = ("CALL", pending_callee)
            elif line[0].isdigit() or line[0] in "+-*":
                parts = line.split()
                cost = int(parts[1]) if len(parts) > 1 else 0
                if isinstance(pending_callee, tuple):
                    calls[cur_fn][pending_callee[1]] += cost
                    pending_callee = None
                elif cur_fn is not None:
                    self_cost[cur_fn] += cost
    return self_cost, calls, call_counts


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("profile")
    ap.add_argument("--top", type=int, default=25)
    ap.add_argument("--paths", type=int, default=3)
    ap.add_argument("--root", default="{main}")
    ap.add_argument("--depth", type=int, default=40)
    ap.add_argument("--exclude", default=r"Middleware|Pipeline|Antlers\\Language|Http\\Responses|Statamic\\View\\View|Antlers\\Engine|Kernel|Router|Route->|Controller|Container|Facade|BoundMethod|Illuminate\\Support\\Collection|HigherOrder|Arr::|Collection->|php::")
    ap.add_argument("--callees", help="print the callees of functions matching this substring")
    args = ap.parse_args()

    cache = args.profile + ".pickle"
    if os.path.exists(cache) and (not os.path.exists(args.profile) or os.path.getmtime(cache) > os.path.getmtime(args.profile)):
        with open(cache, "rb") as fh:
            self_cost, calls, call_counts = pickle.load(fh)
    else:
        self_cost, calls, call_counts = parse(args.profile)
        with open(cache, "wb") as fh:
            pickle.dump((dict(self_cost), {k: dict(v) for k, v in calls.items()}, dict(call_counts)), fh)
    incl = {fn: self_cost.get(fn, 0) + sum(c for callee, c in calls.get(fn, {}).items() if callee != fn)
            for fn in set(self_cost) | set(calls)}
    total = incl.get("{main}", sum(self_cost.values())) or 1
    unit = 1e5  # xdebug 3 cachegrind time unit is 10ns

    print(f"total {total / unit:.0f} ms\n\ntop self time:")
    for fn, c in sorted(self_cost.items(), key=lambda kv: -kv[1])[: args.top]:
        print(f"  {c / unit:9.1f} ms {100 * c / total:5.1f}%  {call_counts.get(fn, 0):>9} calls  {fn}")

    if args.callees:
        for fn in sorted((f for f in incl if args.callees in f), key=lambda f: -incl[f])[:3]:
            print(f"\ncallees of {fn} [{incl[fn] / unit:.0f} ms incl]:")
            for k, c in sorted(calls.get(fn, {}).items(), key=lambda kv: -kv[1])[: args.top]:
                print(f"  {c / unit:9.1f} ms  {k}")
        return

    skip = re.compile(args.exclude)
    print("\ntop inclusive time (statamic, plumbing excluded):")
    for fn, c in sorted(((f, c) for f, c in incl.items() if "Statamic" in f and not skip.search(f)), key=lambda kv: -kv[1])[: args.top]:
        print(f"  {c / unit:9.1f} ms {100 * c / total:5.1f}%  {call_counts.get(fn, 0):>9} calls  {fn}")

    # Call paths: start at --root, branch on its heaviest distinct callees, then
    # follow the heaviest callee down (plumbing is walked through, not printed)
    # until nothing below is worth 2% of the total.
    roots = [fn for fn in incl if args.root in fn]
    root = max(roots, key=lambda f: incl[f]) if roots else "{main}"

    def descend(start: str, seen: set[str]) -> list[str]:
        node, path = start, [start]
        for _ in range(args.depth * 4):
            kids = [(k, c) for k, c in calls.get(node, {}).items() if k not in seen and c >= 0.02 * total]
            if not kids:
                break
            node = max(kids, key=lambda kv: kv[1])[0]
            seen.add(node)
            path.append(node)
        return path

    if args.paths:
        print(f"\nheaviest call paths from {root} ({incl.get(root, 0) / unit:.0f} ms):")
    firsts = sorted(((k, c) for k, c in calls.get(root, {}).items() if k != root), key=lambda kv: -kv[1])
    used: set[str] = {root}
    shown = 0
    for first, _ in firsts:
        if shown >= args.paths:
            break
        path = descend(first, set(used) | {first})
        visible = [fn for fn in path if not skip.search(fn)] or path
        if len(visible) < 2 and len(firsts) > args.paths:
            continue
        shown += 1
        used.add(first)
        print(f"\n  path {shown}:")
        print(f"    {root}  [{incl.get(root, 0) / unit:.0f} ms incl]")
        for depth, fn in enumerate(visible[: args.depth], 1):
            print(f"    {'  ' * min(depth, 14)}-> {fn}  [{incl.get(fn, 0) / unit:.0f} ms incl, {self_cost.get(fn, 0) / unit:.0f} ms self]")

if __name__ == "__main__":
    main()
