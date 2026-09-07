# Maintenance page

`maintenance.php` is WordPress's own maintenance drop-in. Install it as
`wp-content/maintenance.php`; it does nothing until a `.maintenance` file exists
in the site root.

```bash
# on
php -r 'file_put_contents(".maintenance", "<?php \$upgrading = " . (time() + 86400) . ";");'

# off
rm .maintenance
```

Core loads it at `wp-settings.php` line ~79 — before mu-plugins, plugins, the
theme, the REST API, and before `require_wp_db()` at line ~136. So it stops the
whole site at once (storefront, wp-admin, wp-login.php, REST / Store API,
admin-ajax, XML-RPC, WP-Cron) and keeps working with the database down.
Recovery is a file delete, needing no database and no WP-CLI.

Two details are load-bearing and are documented in the file itself: the
`.maintenance` timestamp is written in the **future**, because core expires
maintenance after 10 minutes and the `enable_maintenance_mode` filter cannot
extend it; and the drop-in sets `503` + `Retry-After` itself, because core's
drop-in branch is `require_once` + `die()` and never sets a status, so a custom
drop-in otherwise serves `200 OK` and search engines index the holding page.

The page is static by necessity — there is no database at that point in the
boot, so the store name cannot come from site settings.

> Not covered by `.urumi.yml`'s `folder_mappings` (`themes/`, `mu-plugins/`,
> `plugins/`), so this file does not deploy itself. Copy it into
> `wp-content/maintenance.php` on the workspace.
