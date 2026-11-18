# TWTH Site Health Report

A read-only WordPress plugin from TWTH Consulting, required on every site TWTH hosts or maintains under a retainer. Every day it emails a health and security report for the site it's installed on, including any known vulnerabilities in the installed versions of WordPress, plugins and themes. It changes nothing on the site's content or settings.

## What the report covers

- **Known vulnerabilities:** WordPress core, every installed plugin (active or not) and every theme, checked daily against [WPVulnerability](https://www.wpvulnerability.net/), an open database combining Wordfence, Patchstack, WPScan and CVE data. Each finding lists severity, CVSS score, CVE and the version that fixes it. When anything is found, the email subject says "AT RISK".
- **Security:** WordPress core files compared against the official checksums from wordpress.org, unexpected PHP files in `wp-admin`, `wp-includes` and `uploads`, the number of administrator accounts, and plugin, theme and core updates that are waiting.
- **Cron:** whether WP-Cron is replaced by a real cron job, overdue events, and background hooks that run every 15 minutes or more often.
- **Action Scheduler:** queue size by status, failed and past-due jobs, and the busiest and most-failing hooks.
- **Database:** total size, the largest tables, autoloaded options and expired transients.
- **WooCommerce** (only when it's installed): HPOS status, orders, products, sessions and logs.
- **Software inventory:** every installed plugin (including inactive ones), must-use plugin and theme, with version numbers.

Each email ends with a JSON copy of the same data, so the reports can be reviewed automatically.

## Install

1. Download this repository as a zip, or use the release zip.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip, and activate it.
3. Go to **Tools → TWTH Site Health** and click **Preview report**.

The first report goes out the next morning at 6:00 am, site time, and then daily.

## Required on sites TWTH hosts or maintains

On activation the plugin installs a small must-use file, `wp-content/mu-plugins/twth-site-health-guard.php`, that:
- keeps the plugin active (bulk "Deactivate" has no effect) and hides its Deactivate and Delete links;
- is put back automatically if someone deletes it;
- emails TWTH if the plugin's files are removed.

If the plugin is deactivated anyway (only possible after removing the guard by FTP), it emails TWTH right away, naming the user who did it. A site that stops sending its daily report is flagged as silent on the TWTH dashboard.

## Updates

Sites update **only from published GitHub Releases**. Commits to `main` on their own never reach the sites.

To release a change:
1. Commit it to `main`, with the `Version:` number in `twth-site-health.php` raised.
2. On GitHub, go to **Releases → Draft a new release**, create a tag with the same version (for example `v1.3.0`), and publish it.

Every site installs the new version automatically through WordPress's normal update check, which runs about twice a day.

## Settings

The reports go to the address in `TWTH_HEALTH_TO`. To send one site's reports somewhere else, add this line to that site's `wp-config.php`:

```php
define( 'TWTH_HEALTH_TO', 'someone@example.com' );
```

## Commands

- `/wp-admin/?twth_health=preview`: show the report in the browser (admins only).
- **Tools → TWTH Site Health**: preview or send the report now (admins only).
- `wp twth-health preview` or `wp twth-health send`: the same, from WP-CLI.

Updates are handled by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), which is MIT licensed.

## Removing it from a site (for example, a client who leaves)

The guard is designed to resist removal from inside WordPress, so take it off through the hosting file manager (SiteGround: Site Tools → Site → File Manager) or SFTP, in this order:

1. Delete `wp-content/mu-plugins/twth-site-health-guard.php`.
2. Delete the folder `wp-content/plugins/twth-site-health`.

WordPress then drops the plugin from its list on its own. No alert is sent when the guard goes first. Then tell Claude to retire the site on the TWTH Site Watch dashboard so it isn't flagged as silent.
