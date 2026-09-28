---
name: seo-ops-execution-guard
description: >-
  Always apply during coding, editing, testing, or debugging tasks in seo-ops.
  Prevents retry loops, scope drift, verification creep, and architecture
  invention. Defines hard-stop conditions that require the agent to STOP and
  ASK the user rather than continue unsafely. Activate whenever performing
  implementation work in omnichannel-addons or related seo-ops repositories.
---

# seo-ops-execution-guard

> Stop retry loops and scope drift before they waste quota or damage architecture.

This is a **behavioral guard**, not a coding feature. It governs how the agent
executes tasks — not what it builds.

---

## Execution State Machine

After every failure or unexpected scope expansion, explicitly reason about which
state applies before taking the next action.

### PROCEED

Continue normally when:

- Command succeeds.
- Test failure is understood and directly related to the scoped change.
- Next action clearly advances the requested task.
- Current files/classes/services remain within expected scope.

### CHANGE_STRATEGY

After the **first** failure of a command or action:

1. Inspect the actual failure output.
2. Identify the root cause.
3. Choose a **materially different** method.

Do **not** immediately retry the same or near-identical command.

**BAD**: `command A` fails → `command A` with tiny quoting change → repeat.
**GOOD**: `command A` fails → understand the error → use a different reliable approach once.

### STOP_AND_ASK

The agent **must stop implementation** and ask the user when **any** hard-stop
condition below fires. Do not "try one more thing".

---

## Hard-Stop Conditions

### 1 — Repeated Command Failure

For the **same purpose**:

- First failed attempt → **CHANGE_STRATEGY**
- Second failed attempt → **STOP_AND_ASK**

A syntactically modified command serving the same purpose counts as the **same
attempt family**.

Examples of one attempt family:

- `php artisan tinker` quoting variants
- `Get-ChildItem` recursive variants searching for the same file
- Different `grep` / `Select-String` commands trying to discover the same thing
- `curl` / `Invoke-WebRequest` / browser login attempts for the same goal
- Test commands changed only to capture output differently

Do not hide retry loops behind slightly different shell syntax.

### 2 — Tool Loop

Stop if a repeating sequence appears without a material new hypothesis:

```
read → command → error → read → modified command → error → repeat
```

or:

```
test → inspect log → rerun same test → inspect another log → rerun same test
```

Two cycles are enough. **STOP_AND_ASK.**

### 3 — Scope Drift

Before touching a file outside the expected task scope, ask:

> "Is this file directly required to implement or test the user's requested behavior?"

If **no** or **uncertain**: do not edit it. **STOP_AND_ASK** if continuing
appears to require unrelated modules.

Triggers:

- A Keywords UI task suddenly touching Site Sync, media migrations, Help,
  Seeding, Support Ticket, or unrelated Domain resources.
- An Article Editor task suddenly changing Site Network, sync pipeline,
  unrelated migrations, or Agent Runtime.

Do not fix unrelated failing tests by editing unrelated product code.
Report the unrelated failure instead.

### 4 — Architecture Invention

If the requested behavior cannot be implemented cleanly through the current
architecture and the agent believes it needs to:

- Create a parallel service, read model, persistence table/column, or new route/API
- Create a new classification system
- Replace an existing SSOT
- Introduce a new dependency or framework

**and the prompt did not explicitly request that** → **STOP_AND_ASK.**

The question must state:

1. Current path found
2. Why it appears insufficient
3. Proposed architectural change
4. Smallest alternative

Then wait for user approval.

### 5 — Verification Creep

Verification must stay proportional to the task.

Do **not** turn a small implementation into environment/tooling work:

- Do not search for Chrome / Edge / Playwright.
- Do not install browser tooling.
- Do not inspect login/session internals.
- Do not build ad-hoc browser scripts.
- Do not configure new test infrastructure just to verify a small change.
- Do not investigate unrelated auth/session configuration.
- Do not create scratch automation ecosystems.

Instead report: *"Real browser verification not performed."*

If verification requires such setup → stop verification and report it.

### 6 — Broad Discovery Creep

Source priority for seo-ops:

1. Current local source / available repo MCP
2. Exact referenced files/classes/services
3. Narrow local search only when necessary

Do **not** default to:

- Recursive `D:\work` scans
- Git history
- Remote GitHub browsing
- Repository-wide discovery
- Searching old omnichannel repos
- Unrelated package exploration

If the expected source cannot be found after **two** narrow searches →
**STOP_AND_ASK.** Do not keep widening search radius automatically.

### 7 — Repeated Edit/Revert

If the agent edits the same logical implementation, then reverts/rewrites it,
then is about to replace it a **third** time because the approach is still
unclear → **STOP_AND_ASK.**

Do not keep redesigning live code until something passes.

### 8 — Unrelated Test Failure

If a focused test exposes a failure clearly **unrelated** to the requested
change:

1. Do **not** repair the unrelated subsystem automatically.
2. Report: failing test, why it appears unrelated, whether the scoped
   implementation itself passed.
3. **STOP_AND_ASK** if that failure blocks completion.

### 9 — Unknown User Intent

When two materially different valid implementations exist and choosing one
would change product semantics, persistence, architecture, UX behavior, API
compatibility, or performance characteristics → do **not** pick one just to
continue. Ask the user to choose.

Minor mechanical decisions do not require questions.

---

## Command Discipline

- Never retry a failed command unchanged.
- Never retry the same command family more than twice.
- Do not run commands merely because "maybe this will help".
- Every command must have a stated immediate purpose tied to the active task.
- Prefer direct file/class inspection over shell exploration.
- Prefer focused tests over broad suites.
- Do not use `php artisan tinker` for ordinary verification when direct
  source/tests can answer the question.
- Do not create scratch scripts unless they materially simplify a known problem.
- A scratch script that itself fails twice triggers **STOP_AND_ASK**.

## Background Task Discipline

- Do not poll a background task endlessly.
- Do not repeatedly create duplicate build/test runs.
- If status checks produce no meaningful progress after two checks beyond
  a reasonable task duration → report that the task appears stalled.
- Do not launch duplicate builds/tests to "make sure".

## Path / Architecture Safety

- Current canonical local source is primary.
- Do not infer that an older `omnichannel-*` architecture is current merely
  from old names.
- Do not replace current services with a newly invented abstraction because
  the agent prefers another design.
- Reuse current SSOTs.
- When a prompt names a class/service/path → treat it as the starting point.

---

## Stop Message Format

When **STOP_AND_ASK** triggers, stop editing and use this format:

```
BLOCKED / NEED USER DECISION

Goal:
<what I was trying to do>

What happened:
<exact failure or ambiguity>

Attempts:
1. <attempt + result>
2. <materially different attempt + result>

Why I stopped:
<which guard condition triggered>

Safe options:
A. <option>
B. <option if applicable>

Recommended next step:
<one concise recommendation>

No further code changes were made after the guard triggered.
```

Do **not** bury the blocker inside a long status report.

---

## Do Not Over-Trigger

This guard must **not** make the agent timid. Do **not** ask the user about:

- Normal syntax fixes
- One failed test with an obvious scoped cause
- Ordinary implementation details
- Renaming a local variable
- Straightforward CSS
- Expected test updates
- Mechanical edits already specified by the task

The purpose is to stop when the agent is **looping, drifting, guessing
architecture, expanding verification/tooling, or operating without a reliable
hypothesis** — not to stop often.

---

## Quota / Efficiency Principle

Treat repeated tool activity as a **correctness signal**, not merely a cost
concern. A task that keeps requiring new discovery, repeated shell attempts,
or unrelated verification may indicate the chosen path is wrong.

**"Keep trying until something works" is explicitly prohibited.**

---

## Project-Specific Examples

### Example A — Verification Creep (BAD → GOOD)

**BAD:** Small CSS task → browser automation unavailable → search Chrome →
search Edge → search Playwright → inspect login → inspect session driver.

**GOOD:** Browser unavailable → skip browser verification → report
*"Real browser verification not performed."*

### Example B — Unrelated Failure (BAD → GOOD)

**BAD:** Focused SEO test fails because unrelated media migration errors →
edit media migration to make suite green.

**GOOD:** Identify unrelated failure → do not edit migration → report blocker.

### Example C — Command Retry Loop (BAD → GOOD)

**BAD:** PowerShell/PHP quoting command fails → try 5 quoting variants.

**GOOD:** Attempt 1 fails → materially change strategy → attempt 2 fails →
**STOP_AND_ASK.**

### Example D — Architecture Invention (BAD → GOOD)

**BAD:** Cannot cleanly add requested behavior using current service →
silently create a second service/read-model/path.

**GOOD:** **STOP_AND_ASK** before architecture divergence.

### Example E — Normal Work (No Guard Needed)

Focused test fails because an expected method was renamed by this task →
fix scoped test/code → rerun once → pass → continue.
