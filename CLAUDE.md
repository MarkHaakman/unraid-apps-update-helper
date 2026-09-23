# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

An Unraid plugin ("App Update Helper") that adds a Docker tab page showing, for every running/stopped container, the locally installed version vs. the newest version available in the container's registry, with a classification of the update (Major/Minor/Patch/Build). It was scaffolded from [unraid-plugin-template](https://github.com/dkaser/unraid-plugin-template).

The `unraid-plugin-development-guide/` directory is a separate, independent git repository (a Jekyll docs site) vendored into this working copy for reference only. It is git-ignored (see `.gitignore`) and not part of this plugin's source or build — consult it for general Unraid plugin conventions (`.page` file format, `event/*` hooks, PLG entity system, etc.) but don't treat its example paths/scripts as this repo's actual build process.

## Repository layout

- `src/usr/local/emhttp/plugins/app-update-helper/` — the actual plugin payload, deployed verbatim to that path on Unraid:
  - `api.php` — backend: inspects running Docker containers via `docker inspect`, resolves each image's registry/repository/tag, queries the registry API (Docker Hub / GHCR / etc.) for the remote image's OCI labels, extracts and compares semantic versions, and returns JSON. Results are cached in `/tmp/docker_versions_cache_v4.json` for 1 hour per `registry/repository:tag` key.
  - `AUH-Docker.page` — the `.page` file (Unraid's PHP+HTML tab-page format) that renders the "Docker" tab UI; menu placement is controlled by the `Menu="Docker"` header line. It fetches `api.php` via jQuery `$.getJSON` and renders the results table client-side.
  - `README.md` — plugin description shown in the Unraid Community Applications listing.
- `src/install/slack-desc` — Slackware package description shown during package install.
- `plugin/plugin.json` — build-time metadata (plugin name, package name, author, min Unraid version, icon, support URL) consumed by the release tooling.
- `plugin/plugin.j2` — Jinja2 template that renders the final `.plg` XML descriptor (install/remove scripts, download URL, SHA256) using `plugin.json` values and env vars (`PLUGIN_VERSION`, `PLUGIN_CHANGELOG`, `PLUGIN_CHECKSUM`, `GITHUB_REPOSITORY`) injected by the release action.
- `phpstan.neon` / `.php-cs-fixer.dist.php` — static analysis and formatting config, scoped to `src/`.
- `commitlint.config.js` — enforces Conventional Commits on every commit message.

## Build, package, and release

There are no local build scripts in this repo — packaging (`.txz` build) and PLG generation are handled entirely by the external [`dkaser/unraid-plugin-release-action`](https://github.com/dkaser/unraid-plugin-release-action) GitHub Action, triggered by `.github/workflows/release.yml` on `release: prereleased|released` events. It consumes `plugin/plugin.json` and `plugin/plugin.j2` to produce the package and `.plg` file and attaches them to the GitHub release. There is no local equivalent to run this build — don't attempt to write one unless asked.

## Linting and CI

`.github/workflows/lint.yml` ("Commit Quality") runs on every push to `main` and every PR, with three independent jobs:

- **PHP-CS-Fixer** — `docker run oskarstark/php-cs-fixer-ga --diff --dry-run`, using the ruleset in `.php-cs-fixer.dist.php` (PSR-12 plus a number of stricter rules — risky rules allowed). Locally: `composer install` then `vendor/bin/php-cs-fixer fix --diff --dry-run` (or drop `--dry-run` to apply fixes).
- **PHPStan** — level 9 analysis over `src/**/*.php` and `*.page` (see `phpstan.neon`; two ignore rules exist specifically for symbols only available inside a live Unraid environment — `require_once` of `/usr/local/emhttp/plugins/*.php` files and the `autov`/`parse_plugin_cfg` dynamix helper functions). Locally: `composer install` then `vendor/bin/phpstan analyse`.
- **Commitlint** — validates commit messages against `@commitlint/config-conventional` (Conventional Commits). Applies to the commit range being pushed/PR'd, so write commit messages in `type(scope): subject` form (e.g. `fix: ...`, `feat: ...`).

There is no automated test suite in this repository.

## Working on `api.php`

- Registry auth follows the standard Docker Registry v2 Bearer token flow: probe `/v2/` for a `WWW-Authenticate` realm, fall back to `https://$registry/token` (needed for registries like GHCR that don't advertise the realm on an unauthenticated `HEAD /v2/`), then request a pull-scoped token.
- `lscr.io` images are silently rewritten to pull from `ghcr.io` — LinuxServer.io publishes to both, but only GHCR's config blob is reliably used here.
- Multi-arch manifest lists are resolved by picking the `amd64` platform entry (matches Unraid's target architecture).
- Version comparison (`compareVersions`) is intentionally not a full semver library — it does staged comparisons (major → minor → patch → build suffix) and returns one of `"Major"`, `"Minor"`, `"Patch"`, `"Build"`, `"Update Available"` (unparseable but different), or `""` (no update / unknown).
- The on-disk cache filename is versioned (`_v4`); bump the suffix if you change the cached data shape so stale caches from prior plugin versions aren't misread.
