# Seeding migrations (`omi_seeding`)

Active business schema (2026-09-09+):

- `seeding_topics` — shared topics + requirement snapshot at share time
- `seeding_link_assignments` — Creator shared link list (title/url/target_per_day); independent of Topic
- `seeding_reports` — report commit point (proof + comment + optional seed link)

Owned via `config/addon_migration_ownership.php` → connection `omi_seeding`.
Do not run against `omi_seo_ai`.
