# Static Cache Clean — Agent Guide

## What this package does

This is a minimal Statamic CMS addon that provides a single Artisan command (`static-cache:clean`) to delete orphaned static cache files. It solves a race condition in Statamic's `FileCacher`: cached HTML files can remain on disk after their URL is dropped from the Redis index, causing the web server to keep serving stale pages indefinitely.

## Package structure

```
src/
  StaticCacheCleanProvider.php          # Laravel service provider — registers the command
  Console/Commands/
    CleanStaticPageCache.php            # The only command; all logic lives here
composer.json
README.md
```

No config files, no views, no migrations, no routes.

## The command

**Signature:** `static-cache:clean`

**Options:**
| Option | Description |
|---|---|
| `--dry-run` | List orphaned files without deleting them |
| `--format=json` | Emit a single JSON line instead of human-readable output (useful for automation) |

**JSON output schema** (`--format=json`):
```json
{
  "status": "success" | "error",
  "dry_run": true | false,
  "count": 3,
  "files": ["/public/static/about/index.html", "..."]
}
```

**Algorithm:**
1. Call `$cacher->getDomains()` → for each domain call `$cacher->getUrls($domain)` → compute expected file path via `$cacher->getFilePath(url, site)` → build a set for O(1) lookup.
2. Walk all cache directories from `$cacher->getCachePaths()`, find every `.html` file.
3. Delete any `.html` file whose path is not in the expected set.
4. After deletion, remove the parent directory if it is now empty (never walks above the cache root).

## Supported environments

| Dependency | Constraint |
|---|---|
| PHP | ^8.3 |
| Laravel (illuminate/support) | ^11.0 \|\| ^12.0 \|\| ^13.0 |
| Statamic CMS | ^5.0 \| ^6.0 |
| orchestral/testbench (dev) | ^9.0 \|\| ^10.0 |

**Only the `FileCacher` driver is supported.** The command exits with `FAILURE` and a clear error if the app is configured to use any other cacher.

## Key Statamic APIs used

- `Statamic\StaticCaching\Cachers\FileCacher::getDomains()` — collection of cached domains
- `FileCacher::getUrls(string $domain)` — collection of cached URL paths for a domain
- `FileCacher::getFilePath(string $url, ?string $site)` — absolute path to the HTML file for a URL
- `FileCacher::getCachePaths()` — associative array of `siteHandle => absoluteDir`
- `Statamic\Facades\Site::findByUrl(string $url)` — resolve a full URL to a site object

## Common tasks

**Check what would be cleaned without deleting:**
```bash
php artisan static-cache:clean --dry-run
```

**Run from a script or queue job and capture results:**
```bash
php artisan static-cache:clean --format=json
```

**Actual clean:**
```bash
php artisan static-cache:clean
```

## Statamic v5 → v6 upgrade notes

- The `statamic/cms` constraint was widened to `^5.0|^6.0`.
- No internal Statamic APIs used by this package changed between v5 and v6.
- The command does not touch the Control Panel, Vue components, or date handling — none of the high/medium-impact v6 changes apply here.
- If upgrading the host app to Laravel 13, `illuminate/support ^13.0` is already in the constraint.

## What NOT to change without care

- The path normalisation regex (`preg_replace('#/+/#', '/', ...)`) was added deliberately to handle double-slash edge cases from `getFilePath`. Do not remove it.
- The safeguard `$parent = dirname($fullPath, 2)` with the `$inRoot` check prevents the command from deleting the cache root directory itself. Keep this logic intact.
