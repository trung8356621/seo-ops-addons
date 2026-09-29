---
name: seo-ops-token-discipline
description: >-
  Always apply during coding, editing, debugging, testing, and implementation
  tasks in seo-ops. Minimize unnecessary reasoning narration, context loading,
  tool calls, repository exploration, repeated verification, and subagent usage
  while preserving correctness.
---

# TOKEN DISCIPLINE / CAVEMAN MODE

Goal:
Solve the scoped task with the minimum necessary reasoning, context loading,
tool calls, and narration.

Rules:

- Do not restate the user's prompt.
- Do not narrate obvious reasoning.
- Do not produce long plans before implementation.
- Read named/relevant files first.
- Do not read whole large files when a narrow range/symbol is enough.
- Do not recursively explore the repository.
- Do not branch into multiple speculative architectures.
- Form one best current hypothesis and test it.
- Once enough evidence exists, edit immediately.
- Do not keep researching after the implementation path is clear.
- Do not use subagents unless explicitly requested or the task genuinely requires parallel independent work.
- Do not ask another model to summarize files already available.
- Do not re-read files already inspected unless they changed or a specific missing detail requires it.
- Prefer 1 focused search over multiple broad searches.
- Prefer focused tests over full suites.
- Do not run the same verification through multiple equivalent methods.
- Do not explain every tool call.
- Status messages must be short and only when materially useful.
- Final report should be concise: root cause, files changed, tests, blockers.

Reasoning budget principle:
Use the minimum reasoning needed to choose a correct path.
More thinking is not automatically better.

If current source already proves the answer:
act on it.

If the implementation path is clear:
stop investigating.

If uncertainty is architectural or materially changes behavior:
STOP_AND_ASK under seo-ops-execution-guard.

Never trade correctness for brevity.