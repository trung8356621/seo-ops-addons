# Social Automation — RPA Admin / Runner Integration Handoff

Status: **CODE COMPLETE** (Phases A–E + 7–11 + RPA Admin auth/sync + Filament Scripts/Widgets/Workflows UI). Production remains locked. **E2E / FINAL INTEGRATION / RPA Client NOT RUN.**

Base path (Runner + Service API packages): `/api/v1/services/{service}/social-automation/...`  
`{service}` must resolve to public slug `social-automation`.

RPA Admin **user** auth + Script sync (separate from Runner credentials):  
`/api/v1/social-automation/rpa-admin/...` — see `rpa-social-automation-v2/docs/SEO-OPS-SCRIPT-REGISTRY.md`.

---

## 1. Authentication & scopes

### RPA Admin user (admin|owner)

| Rule | Detail |
|---|---|
| Login | `POST /api/v1/social-automation/rpa-admin/login` with SEO-OPS email/password |
| Session | Opaque Bearer token; stored as SHA-256 hash in `sa_rpa_admin_sessions` |
| Roles | `admin`, `owner` allowed; `staff` → `role_staff_forbidden` |
| Identity | Server-derived `user_id` only — client `user_id` stripped |
| Distinct | Not Runner `service_api_credentials` |

### Service API (packages + Runner)

| Rule | Detail |
|---|---|
| Bearer | Core `service_api_credentials` only |
| Forbidden | OPS-SERVER `service_key`, public enrollment, unauthenticated register |
| Middleware | `EnsureSocialAutomationServiceApi` + `service.api.scope:*` |

### Admin scopes

| Scope | Endpoints |
|---|---|
| `social-automation:package:read` | `GET .../packages`, `GET .../packages/{packageKey}?version=` |
| `social-automation:package:publish` | `POST .../packages/publish` |
| `social-automation:admin:task` | `GET/POST .../tasks`, `POST .../schedules`, `POST .../widgets/compose-input`, `POST .../workflows/compose-preview` |
| `social-automation:admin:dispatch` | `POST .../dispatch/manual` |

### Runner scopes

| Scope | Endpoints |
|---|---|
| `social-automation:runner:agent` | `POST .../runner/register`, `POST .../runner/agents/{uuid}/heartbeat` |
| `social-automation:runner:session` | `POST .../runner/agents/{uuid}/sessions/sync` |
| `social-automation:runner:job` | claim / ack / progress / result / artifacts |

---

## 2. Stable HTTP / error codes

Business errors return JSON `{ "ok": false, "errors": ["code"] }` — not HTTP 500.

| Code family | HTTP |
|---|---|
| unauthorized / unauthenticated | 401 |
| credential_mismatch, cross_agent_forbidden, agent_disabled | 403 |
| `*_not_found` | 404 |
| version_digest_conflict, stale_attempt, attempt_fence_mismatch, invalid_transition, account_locked | 409 |
| validation / missing fields | 422 |
| schema_unavailable, package_registry_unavailable | 503 |

---

## 3. Package registry (Admin)

### Rules

- Kinds: `script` \| `widget` \| `workflow`
- `script` = immutable RPA Script release (variables/steps/assets/video_demo). Not task-dispatchable.
- `widget` = form layer over a Script (`form_fields` + `script_*` pin) **or** legacy steps-only widget
- `workflow` = sequential Widget steps, **no account switching**; may pin widget dependencies
- Version + `content_digest` (SHA-256 of canonical JSON)
- Same key/version/digest → `outcome=idempotent` (200)
- Same key/version, different digest → `version_digest_conflict` (409)
- Published releases immutable; tasks pin `package_key` + `package_version` (+ digest) for **widget|workflow** only
- Top-level `manifest.script` remains forbidden (executable). Script kind uses `script_id`.
- Packaging (post+comment as one widget vs two) is Admin composition — server does not hardcode

See fixtures: `tests/Fixtures/contracts/01_*.json` … `03_*.json`, `12_*` … `14_*`

Compose (Admin task scope):
- `POST .../widgets/compose-input` — Manual / AI Auto → validated inputs
- `POST .../workflows/compose-preview` — Script→Widget→Workflow mapping preview

---

## 4. Agent / session / account (Runner)

### Identity

- Strong identity = `platform` + verified `platform_account_id` (`identity_source=platform_id`)
- Domain tag alone is **not** an account
- Declared email/username alone → `identity_incomplete` (not eligible)

### Session sync

- Never send cookies / passwords / profile paths (rejected)
- `ready_status=ready` ≠ login verified
- Multiple accounts per agent allowed; active bind per session/domain

### Agent states

- Online = recent heartbeat (default 90s)
- `disabled` / `paused` / `error` → not eligible; `disabled` cannot claim new jobs
- Offline → schedule skip, **no catch-up**

Fixtures: `04_*`, `05_*`, `06_*`

---

## 5. Job lifecycle & fencing

```
reserve → claim → ack(running) → progress* → result
                ↘ lease expire → unknown (uncertain; fence cleared)
reserved lease expire → pending (locks released; no auto re-dispatch)
```

### Lease / lock

- Default lease: 300s (extended on progress)
- Session lock: one active job per browser session
- **Account lock**: one active job per `account_id` (atomic `lockForUpdate` on account row inside dispatch transaction)
- Agent capacity: `max_concurrency`

### Attempt fence (required)

On claim, server returns `attempt.attempt_uuid` and stores it in job payload `_fence`.

Runner **must** send `attempt_uuid` on:

- ack, progress, result, artifacts

Mismatch / missing / cleared fence → `stale_attempt` or `attempt_fence_mismatch` (409).

After lease → `unknown`: fence cleared; old Runner cannot complete.  
**No automatic re-publish of social actions** (may already have succeeded).  
Ops path: `human_required` / `cancel`, or reclaim `unknown → claimed` (new fence).

### Idempotency

| Action | Behavior |
|---|---|
| Dispatch | key = `task\|session\|trigger\|account` |
| Schedule fire | key = `schedule_uuid\|fireAt` |
| Claim while claimed/running | returns same fence |
| Result after terminal + same fence | `idempotent: true` |
| Artifact same checksum | `idempotent: true` |

Fixtures: `07_*` … `11_*`

---

## 6. Artifacts

- Stored on local disk; DB keeps path + checksum + metadata (not large blobs)
- Screenshot success → `metadata.phase` = `final` \| `result`
- Screenshot failure → step/phase required
- Requires matching `attempt_uuid`
- No secret keys in metadata

---

## 7. Observability

Job timeline (internal): attempts, events, package pin, account ref (no email), runner device, redacted errors.

Classification examples: `uncertain_outcome`, `timeout`, `runner_offline`, `session_error`, `runner_reported`.

---

## 8. Compatibility / version policy

- Schema version on package publish (`schema_version >= 1`)
- `runtime_compatibility.runner_min` checked at eligibility when present
- Existing migrated tables unchanged for fencing (fence lives in `payload._fence`)
- A–E contracts remain backward compatible; new required field for Runner job APIs: **`attempt_uuid`**

---

## 9. JSON fixtures

Directory: `social-automation/tests/Fixtures/contracts/`

| # | File | Purpose |
|---|---|---|
| 1 | `01_package_publish_success.*` | Valid publish |
| 2 | `02_package_version_conflict.response.json` | Digest conflict |
| 3 | `03_workflow_sequential_widgets.request.json` | Multi-widget workflow |
| 4 | `04_runner_register/heartbeat.request.json` | Enrollment |
| 5 | `05_session_sync_multi_accounts.request.json` | Multi account (**provisional** field notes) |
| 6 | `06_session_sync_missing_identity.request.json` | Weak identity |
| 7 | `07_job_claim.response.json` | Claim + fence |
| 8 | `08_job_success.request.json` | Success result |
| 9 | `09_job_failure_screenshot.request.json` | Failure + shot |
| 10 | `10_stale_result.response.json` | Stale fence |
| 11 | `11_duplicate_request.response.json` | Idempotent replay |
| 12 | `12_script_publish_success.request.json` | Script Registry release |
| 13 | `13_widget_form_over_script.request.json` | Widget form over Script |
| 14 | `14_script_widget_workflow_mapping.request.json` | Compose-preview mapping |

Fields marked `"provisional": true` await joint confirmation with RPA Admin/Runner.

---

## 10. Final integration checklist (DO NOT RUN YET)

### Ops / Anti (once)

1. Confirm deployment allowlist decision for `social-automation`
2. Activate Service + mint Service DB credentials if needed
3. Mint Admin + Runner `service_api_credentials` with scopes above
4. Confirm `omi_social_automation` connection from Service Configure
5. Smoke: package publish → task → manual dispatch → runner claim → result

### RPA Admin

1. Publish widget + workflow packages matching fixtures
2. Create task pinned to release digest
3. Optional schedule; verify offline skip / no catch-up
4. Manual dispatch shortfall behavior when accounts < target

### RPA Runner

1. Register + heartbeat with Bearer credential
2. Session sync with verified `platform_account_id`
3. Claim → echo `attempt_uuid` on all job calls
4. Success screenshot `phase=final`; failure screenshot at step
5. Simulate lease expiry → confirm stale result rejected
6. Duplicate result/artifact → idempotent

### PASS before production unlock

- [ ] Unit suite `SocialAutomationAddon` green on CI/Laragon
- [ ] Contract fixtures reviewed by Admin + Runner owners
- [ ] One controlled integration pass on non-prod
- [ ] No use of `service_key` as Bearer
- [ ] Uncertain jobs (`unknown`) have ops playbook (no blind re-post)

---

## 11. Local verification

```bash
# from omnichannel-client (addons junction)
vendor/bin/phpunit --testsuite SocialAutomationAddon
```

Concurrency proofs are **unit/fake**. Full MySQL race tests: **PENDING FINAL INTEGRATION**.
