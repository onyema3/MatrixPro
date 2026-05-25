# Vendored: plugin-update-checker

This directory contains a vendored copy of
[YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker),
used by `matrix-mlm.php` to poll GitHub Releases for plugin updates.

| Property | Value |
| --- | --- |
| Upstream version | **v5.6** (released 2025-05-20) |
| License | MIT (see `license.txt`) — GPL-compatible |
| Wired in | `matrix-mlm.php`, in the constants block immediately after `MATRIX_MLM_DB_VERSION` |
| Repo polled | `https://github.com/onyema3/MatrixPro/` (overridable via the `matrix_mlm_update_checker_repo` filter) |
| Slug | `matrix-mlm` (must match the install directory name) |

## How update checks work in production

1. Every ~12 hours WordPress fires its update-transient refresh on each
   site running this plugin. PUC hooks into that and asks the GitHub
   Releases API for the latest release on the configured repo.
2. PUC compares the release tag (after stripping a leading `v`) against
   the `Version:` header in `matrix-mlm.php`. If the release tag is
   newer (per `version_compare`), the standard "Update available"
   notice appears in `Plugins → Installed Plugins`.
3. The "Update Now" link downloads the release's source ZIP, unpacks
   it, and lets WordPress's built-in upgrader install it over the
   existing plugin directory.

## To ship a new version

1. Bump the `Version:` header **and** the `MATRIX_MLM_VERSION`
   constant in `matrix-mlm.php`. They must agree — PUC reads the
   header, but the constant is what every enqueued asset uses as a
   cache-buster, so out-of-sync values will cause stale CSS/JS on
   upgrade.
2. Merge to `main`.
3. Cut a [GitHub Release](https://github.com/onyema3/MatrixPro/releases/new)
   on the merge commit. Tag it as `v2.0.1` (or `2.0.1` — both work).
4. Customer sites pick up the update on their next 12-hour transient
   refresh, or immediately if an admin clicks "Check again" on the
   Updates screen.

The release-tag filter in `matrix-mlm.php` ignores any tag that
doesn't match `^v?\d+\.\d+(\.\d+)?([.\-]\w+)?$`, so non-version tags
(`hotfix-foo`, `staging`, etc.) won't be offered as updates.

## To update PUC itself

```bash
# From the repo root:
WORK=$(mktemp -d)
NEW_VERSION=5.7   # whatever the new tag is
curl -sL "https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/v${NEW_VERSION}.tar.gz" -o "$WORK/puc.tar.gz"
tar -xzf "$WORK/puc.tar.gz" -C "$WORK"
rm -rf vendor/plugin-update-checker
mkdir -p vendor/plugin-update-checker
cp -a "$WORK/plugin-update-checker-${NEW_VERSION}/." vendor/plugin-update-checker/
rm -f vendor/plugin-update-checker/README.md   # we keep README-MATRIX.md instead
# Update the version row in this file.
```

PUC's namespace versions itself (`v5p6`, `v5p7`, etc.) so multiple
plugins on the same site can each load their own pinned copy without
collision. The factory call in `matrix-mlm.php` is namespace-pinned
to `v5`, which auto-resolves to the latest `v5pX` we ship — so a
minor PUC bump within the v5 line should not require any code change
in `matrix-mlm.php`. Major version bumps (e.g. v5 → v6) will.

## Disabling auto-updates on a site

```php
// In wp-config.php or a must-use plugin:
add_filter('matrix_mlm_update_checker_repo', '__return_false');
```

This skips the PUC bootstrap entirely — no transient hooks registered,
no GitHub API calls, no admin notices. Useful for white-labelled
installs that ship updates through their own pipeline.
