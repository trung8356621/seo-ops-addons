# Social Automation migrations (`omi_social_automation`)

Owned via client `config/addon_migration_ownership.php` → connection `omi_social_automation`.

| Migration | Phase |
|-----------|-------|
| `2026_10_21_100000_create_sa_package_registry_tables.php` | B |
| `2026_10_21_110000_create_sa_agent_session_account_tables.php` | C |
| `2026_10_21_120000_create_sa_task_job_tables.php` | D/E |
| `2026_10_21_130000_create_sa_artifact_audit_tables.php` | E |

**Do not run migrations during implementation freeze.** Owner runs once DB plane exists.

Rules:
- Prefix `sa_`
- FK only within `omi_social_automation`
- Never FK to core/seo/seeding databases
- Never store cookies/passwords/tokens/profile paths
