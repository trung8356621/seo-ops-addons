---
name: seo-ops-token-discipline
description: >-
  Always apply during coding, editing, debugging, testing, and implementation
  tasks in seo-ops. Minimize unnecessary context loading, tool calls,
  repository exploration, repeated verification, and subagent usage while
  preserving correctness.
---

# Token Discipline

Solve the scoped task with the minimum necessary context loading and tool calls.

## Rules

- Read named and relevant files first.
- Read narrow ranges or symbols instead of whole large files when sufficient.
- Avoid unnecessary repository-wide exploration and speculative architecture branches.
- Form the best current hypothesis, test it, and edit once evidence is sufficient.
- Do not keep researching after the implementation path is clear.
- Avoid unnecessary subagents and tool calls.
- Do not ask another model to summarize files already available.
- Re-read files only after they change or when a specific missing detail requires it.
- Prefer one focused search over multiple broad searches.
- Prefer focused tests over full suites.
- Do not repeat equivalent verification or enter retry loops.

If uncertainty is architectural or materially changes behavior, use `STOP_AND_ASK` under `seo-ops-execution-guard`.

Never trade correctness for efficiency.
