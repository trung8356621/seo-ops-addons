# Runner failure recovery (policy)

Locked business rules:

| Situation | Server behavior |
|---|---|
| All agents offline at schedule fire | Skip trigger; record `skipped_offline`; **no catch-up** |
| Partial eligible accounts | Dispatch available count; record shortfall; no backlog |
| Runner offline before claim | Job stays `reserved` until lease → `pending` (locks released) |
| Runner offline after claim/running | Lease → `unknown`; fence cleared; attempt `stale` |
| Late result with old attempt | `409 stale_attempt` — **no overwrite** |
| Social action may have succeeded but result lost | Stay `unknown` / escalate `human_required` — **never auto re-post** |
| Session disappears mid-job | Job continues until lease/result; no auto account switch |
| Session sync changes active account mid-job | Job keeps reserved `account_id`; mid-flight rebinding is **DECISION REQUIRED** for ops UI (no auto cancel in V1) |
| Duplicate heartbeat | Upsert heartbeat timestamp; harmless |
| Artifact re-upload same checksum | Idempotent accept |
| Agent disabled with in-flight job | No new claims; fenced result still accepted until lease/fence rules apply |
| Agent disabled | Not eligible for new dispatch |

Workflow: sequential steps on the **same** account/session selected at reserve. No account switching between steps.
