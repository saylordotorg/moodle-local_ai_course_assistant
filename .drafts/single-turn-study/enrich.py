#!/usr/bin/env python3
"""Turn a Redash CSV export (Q1/Q2) into one row per conversation, ready to read
and code. Pairs each learner message with its reply, flags the things that are
mechanical to check so the human pass is spent on judgment instead.

  python3 enrich.py sample.csv > paired.md
"""
import csv, sys, collections, statistics

rows = list(csv.DictReader(open(sys.argv[1])))
convs = collections.OrderedDict()
for r in rows:
    convs.setdefault(r["ref"], []).append(r)

ptoks = [int(r.get("prompt_tokens") or 0) for r in rows
         if r.get("role") == "assistant" and (r.get("prompt_tokens") or "0").isdigit()]
median = statistics.median(ptoks) if ptoks else 0

print(f"# Paired transcripts — {len(convs)} conversations\n")
print(f"Median assistant prompt_tokens: {median:.0f}. "
      f"A reply well below this had little course content in its prompt, "
      f"which is the retrieval-starvation proxy.\n")

for ref, msgs in convs.items():
    msgs.sort(key=lambda r: int(r["timecreated"]) if r.get("timecreated", "").isdigit() else 0)
    user = next((m for m in msgs if m["role"] == "user"), None)
    asst = next((m for m in msgs if m["role"] == "assistant"), None)
    print(f"\n## {ref} — course {user.get('course') or user.get('courseid') if user else '?'}")
    if not asst:
        print("\n**NO ASSISTANT REPLY — this turn broke. Code `A-NONE`.**")
    if user:
        print(f"\n**Learner ({len(user['message'])} chars):**\n\n> "
              + user["message"].strip().replace("\n", "\n> "))
    if asst:
        pt = int(asst.get("prompt_tokens") or 0)
        flag = "  <-- LOW, possible retrieval starvation" if pt and pt < median * 0.5 else ""
        print(f"\n**Reply ({len(asst['message'])} chars, prompt_tokens={pt}{flag}, "
              f"rag_ms={asst.get('rag_latency_ms') or 'null'}, model={asst.get('model_name')}):**\n")
        print("> " + asst["message"].strip().replace("\n", "\n> "))
        if (asst.get("offtopic_count") or "0") not in ("0", "", "None"):
            print(f"\n**Topic guard fired (offtopic_count={asst['offtopic_count']}) — consider `A-REFUSED`.**")
        if asst.get("rating"):
            print(f"\n**Learner rated this: {asst['rating']}"
                  f"{', flagged as hallucination' if asst.get('is_hallucination') in ('1','true') else ''}.**")
    print(f"\n`{ref},,,,,,,,,`  <- code here\n\n---")
