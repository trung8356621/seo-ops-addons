# omnichannel-addons

Peer-addon monorepo. Every folder with `addon.json` is a peer — no submodule hierarchy.

## Layout

Each addon keeps its own `src/`, `database/migrations/`, `resources/`, `tests/`, `routes/`, `config/`, `lang/`.

Compatibility shell: `seo-content-ai-compat/` (legacy slug `seo-content-ai`).

## Local path require

Client requires `omnichannel/addons` via Composer path repository and discovers addons from filesystem (`OMNICHANNEL_ADDONS_PATH` / `client/addons` junction).