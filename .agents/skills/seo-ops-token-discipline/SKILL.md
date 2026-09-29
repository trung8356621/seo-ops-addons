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

## Silent execution

Default behavior during implementation:

- Do not narrate reasoning.
- Do not summarize what you are about to do.
- Do not explain files you just read.
- Do not restate the implementation plan.
- Do not emit progress/status commentary unless the user explicitly asks for it.
- Tool activity itself is sufficient progress indication.
- Once the implementation path is clear, edit immediately.

Visible explanation is allowed only when:

1. `STOP_AND_ASK` / a genuine blocker is triggered.
2. A user decision is required.
3. The user explicitly asks for analysis, review, audit, or report.
4. The task is complete and the final report is being given.

For ordinary coding tasks:

read → edit → test → final report

No running commentary between those stages.

Final reports should stay concise by default:

- root cause
- files changed
- tests/build
- blockers, if any

Only produce a detailed report when the user explicitly asks for one.