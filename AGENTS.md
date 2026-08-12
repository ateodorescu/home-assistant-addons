# AGENTS.md

Guidance for AI agents working in this repository.

## Project overview

Home Assistant add-on repository that ships an **IPMItool HTTP server**. The server wraps `ipmitool`, exposes JSON endpoints, and is consumed by the companion integration [home-assistant-ipmi](https://github.com/ateodorescu/home-assistant-ipmi).

Two deployables share the same Symfony app:

| Path                      | Purpose                                                           |
| ------------------------- | ----------------------------------------------------------------- |
| `ipmi-server/`            | Home Assistant add-on (hassio-addons base 21, s6, nginx, PHP-FPM) |
| `ipmi-server-standalone/` | Standalone Docker image (php-fpm-alpine + supervisor + nginx)     |

Published images:

- Add-on: `ghcr.io/ateodorescu/ipmi-server/{arch}`
- Standalone: `ghcr.io/ateodorescu/ipmi-server-standalone`

## Repository layout

```
.
├── repository.yaml              # HA add-on repository metadata
├── RELEASING.md                 # Edge/dev vs stable release process
├── ipmi-server/
│   ├── config.yaml              # Add-on version, ports, options, image
│   ├── Dockerfile               # Multi-stage build; composer install at image build
│   ├── build.yaml               # Arch base images
│   ├── CHANGELOG.md
│   ├── DOCS.md / README.md
│   └── rootfs/
│       ├── app/                 # Symfony 6.3 application (source of truth for API)
│       └── etc/                 # nginx, php-fpm, s6 service run scripts
└── ipmi-server-standalone/
    ├── Dockerfile               # Copies `ipmi-server/rootfs/app` into image
    ├── nginx.conf
    └── supervisord.conf
```

## Application stack

- **PHP**: `>=8.4` in Composer; runtime in Docker is **PHP 8.4**
- **Framework**: Symfony 8.1 (FrameworkBundle, Process, Routing, Console)
- **Entry point**: `ipmi-server/rootfs/app/public/index.php` → `App\Kernel`
- **Business logic**: almost entirely in `App\Controller\IpmiController`
- **Routes**: YAML in `ipmi-server/rootfs/app/config/routes.yaml` (not attributes)
- **Process execution**: `Symfony\Component\Process\Process` with 50s timeout
- **Vendor**: not committed; installed during Docker build (`composer install`)

PSR-4: `App\` → `ipmi-server/rootfs/app/src/`

## HTTP API

Default host port mapping (add-on): container `80` → host `9595`. Ingress is also enabled.

| Path                                                                    | Handler               | Role                                          |
| ----------------------------------------------------------------------- | --------------------- | --------------------------------------------- |
| `/ui`                                                                   | `WebUiController`     | Ingress web UI (form → fetch/display sensors) |
| `/`                                                                     | `index`               | Device info (bmc/fru/power) + sensors         |
| `/sensors`                                                              | `sensors`             | Sensors only (SDR + DCMI power reading)       |
| `/command`                                                              | `command`             | Raw `ipmitool` via `params` query string      |
| `/power_on` `/power_off` `/power_cycle` `/power_reset` `/soft_shutdown` | chassis power helpers |                                               |

Ingress Open Web UI entry: `ingress_entry: ui` in `ipmi-server/config.yaml` (opens `/ui`).

Common query params for IPMI connection: `host`, `port` (default `623`), `user`, `password`, `interface` (`lanplus`/`lan`/`imb`/`open`; empty = auto-try), `kg_key`, `privilege_level`, `extra`.

Optional secret headers (also supported): `X-Ipmi-Password`, `X-Ipmi-Kg-Key`.

Responses are JSON. Prefer keeping `success`, `message`/`output`, `device`, `sensors`, `states`, and password anonymization behavior stable — the HA integration depends on this shape.

## Coding conventions

- Keep the API surface and JSON response keys backward-compatible unless a major version bump is intentional.
- Always anonymize passwords in logs/error/`debug` output (`anonymizeSecrets`); never echo credentials back.
- Prefer `Process` over shell strings; pass argv arrays to avoid injection.
- Interface auto-detection loops over `$ipmiTypes` when `interface` is empty — preserve that behavior.
- Sensor parsing currently uses `sdr list full` (+ optional DCMI power). `extractFromSensorCommand` exists but is unused; do not remove without checking compatibility.
- Routes live in `config/routes.yaml`; wire new endpoints there and in `IpmiController`.
- Do not vendor-commit `ipmi-server/rootfs/app/vendor/`.
- Pin nginx/apk package versions carefully in the add-on `Dockerfile` (HA VM installs have broken on unpinned nginx before).
- Standalone image must stay in sync with app changes under `ipmi-server/rootfs/app` (it copies that tree).

## YAML / yamllint / Prettier

CI runs both **yamllint** (`.yamllint`) and **Prettier** (`creyD/prettier_action` via hassio-addons CI). **All** YAML is checked, including Symfony files under `ipmi-server/rootfs/app/config/` (not only add-on `config.yaml` / workflows). Markdown and `composer.json` are also Prettier-checked.

When creating or editing YAML, follow these rules (errors fail CI):

- Start every document with `---` (`document-start: present`).
- Do **not** add a trailing `...` (`document-end: present: false`).
- Indent with **2 spaces**; sequence items under a key are indented (`indent-sequences: true`).
- Comments: space after `#` (`# comment`), at least 2 spaces from content when inline, and indent comments like the content they annotate (`comments` / `comments-indentation`).
- No trailing spaces; Unix newlines; file ends with a newline; at most one consecutive blank line.
- Colons: no space before, one space after. Hyphen lists: one space after `-`.
- Braces `{ }` allow 0–1 spaces inside; brackets `[ ]` allow **0** spaces inside.
- `truthy` is an error: bare `yes`/`no`/`on`/`off`/`true`/`false` as unquoted values are flagged in some contexts — quote them or use `# yamllint disable-line rule:truthy` (as in GitHub workflow `on:` keys).
- Line length is a **warning** at 120 chars (non-breakable words/inline mappings allowed).

Prettier reads `ipmi-server/rootfs/app/.editorconfig`. Keep `*.{yaml,yml}` at `indent_size = 2` there so Prettier and yamllint agree (PHP/JSON may stay at 4).

Validate locally before pushing:

```bash
yamllint -c .yamllint .
npx prettier@3.9.6 --check "**/*.{js,md,yaml,yml,json}"
# fix: npx prettier@3.9.6 --write <paths>
# .prettierignore excludes app vendor/ and var/
```

## Local Symfony work

App root: `ipmi-server/rootfs/app`

```bash
composer install
php bin/console
```

There is no test suite checked in yet (`App\Tests\` is reserved in Composer). If adding tests, place them under `ipmi-server/rootfs/app/tests/`.

## CI / release

- CI: `.github/workflows/ci.yaml` → reusable `hassio-addons` addon CI (includes yamllint via `.yamllint`; see **YAML / yamllint** above)
- Deploy add-on: `.github/workflows/deploy.yaml` on release / successful CI on `main`
- Standalone image: `.github/workflows/docker-standalone.yaml` on `main` and `v*` tags

Release process is documented in `RELEASING.md`:

1. Develop with `version: "dev"` in `ipmi-server/config.yaml`
2. For stable: set semver in `config.yaml`, push, publish GitHub release tag `vX.Y.Z`
3. Reset `config.yaml` back to `"dev"` after release
4. Update `ipmi-server/CHANGELOG.md` when releasing

## Security & secrets

- Treat IPMI credentials as sensitive; they arrive as query params today — do not log them.
- Do not commit real secrets, `.env.local`, or credentials. The committed `.env` is for Symfony defaults only.
- `/command` executes arbitrary `ipmitool` params; changes there have high security impact — be conservative.

## Docs to update when behavior changes

- `ipmi-server/README.md` / `DOCS.md` for user-facing usage
- `ipmi-server/CHANGELOG.md` for released changes
- `RELEASING.md` only if the release workflow itself changes
