# Legacy experimental Seeding migrations

These migrations targeted `omi_seo_ai` during early Seeding Topic V2 experiments.

They are **not** part of the canonical Seeding migration plane (`omi_seeding`).

- Do not run on fresh installs
- Do not recreate equivalent tables in `omi_seeding` until the domain model is finalized
- Orphaned tables in `omi_seo_ai` (if any) may be cleaned up later after explicit confirmation

Tables historically created:

- `link_resources`
- `seeding_topics`
- `seeding_topic_links`
