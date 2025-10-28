# WPMUDEV Test Plugin #

This is a plugin that can be used for testing coding skills for WordPress and PHP.

# Development

## Composer
Install composer packages
`composer install`

## Build Tasks (npm)
Everything should be handled by npm.

Install npm packages
`npm install`

| Command              | Action                                                |
|----------------------|-------------------------------------------------------|
| `npm run watch`      | Compiles and watch for changes.                       |
| `npm run compile`    | Compile production ready assets.                      |
| `npm run build`  | Build production ready bundle inside `/build/` folder |

## Release Build & Package Size

Before creating a distributable zip, run `composer install --no-dev && npm run compile` so the `vendor/` autoloader and compiled assets are up to date.

`npm run build` now copies only the runtime code (PHP in `app/` + `core/`, compiled assets in `assets/`, translations, and Composer dependencies) while explicitly excluding `node_modules/`, raw `src/` sources, and tests.  
Quality-tooling Composer packages (`wp-coding-standards`, `phpcompatibility`, `squizlabs/php_codesniffer`, etc.) are also skipped so they no longer bloat the distributable archive.  
This keeps deployments lean without sacrificing any required functionality or third-party libraries (the Google API client still ships from `vendor/`).

## Features

### Google Drive Test
- Save OAuth credentials (encrypted at rest) and launch the Google Drive auth flow directly from the admin screen.
- Upload files, create folders, and browse Drive contents with progress, error handling, and translations.
- All UI strings are run through WordPress i18n helpers and every action communicates via hardened REST endpoints.

### Posts Maintenance
- New top-level "Posts Maintenance" admin page built with React + SUI styling.
- Choose which public post types to scan, watch live progress, cancel scans, and review the last run history.
- Background jobs chunk work into small batches via WP-Cron while storing progress in `wpmudev_posts_maintenance_job`.
- A daily event (`wpmudev_posts_maintenance_daily`) automatically reuses your saved filters.
- Recent cron runs are stored in history (latest 10 entries) so you can audit automation activity.

#### Automation Setup (admin UI)
1. Navigate to **WP Admin → Posts Maintenance**.
2. Under **Automation Schedule**, toggle **Enable daily WP-Cron task** on.
3. (Optional) Toggle **Run multiple times per day** and add up to six HH:MM slots (site timezone).  
   Times entered in the UI are saved exactly and displayed using the site timezone + am/pm formatting.
4. Click **Save Automation Settings** – this persists post-type filters, cron enablement, and time slots.

The UI shows a live “Recent WP-Cron Runs” list so you can confirm the automation keeps firing once server-side cron is configured.

#### Server Cron Requirements
WordPress only executes scheduled batches when `wp-cron.php` (or `wp cron event run`) is triggered. Configure a system-level cron for the web user. Example (runs every 6 seconds via multiple staggered jobs):

```
* * * * * cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 6  && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 12 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 18 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 24 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 30 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 36 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 42 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 48 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 54 && cd /data/apps/php/canvas-symfony5/public/blog && wp cron event run --due-now --allow-root >/dev/null 2>&1
```

Guidelines:
- Keep `DISABLE_WP_CRON` unset/false so WordPress can spawn emergency runs during web requests.
- If you prefer another schedule, ensure the highest frequency matches your desired throughput (each run processes one batch; default batch size is 200 items).
- Running inside Docker? Add the cron entries to the container and ensure `cron`/`crond` is started.

#### Troubleshooting
- **Queued, never progresses** – the plugin now automatically clears stale `_transient_doing_cron` locks and reschedules the worker on every `init`. Make sure the site receives cron triggers (see above). You can still manually clear the lock via `wp option delete _transient_doing_cron`.
- **Finished too early** – when changing batch size, let the current job finish or cancel/reset first. The worker now stores batch size per job to avoid page-skipping caused by mid-run changes.
- **Audit recent runs** – use the “Recent WP-Cron Runs” panel or run `wp option get wpmudev_posts_maintenance_cron_history --format=json` to inspect the last 10 automation executions.

#### WP-CLI
Run the same maintenance routine without opening the admin UI:

```
wp wpmudev posts-maintenance scan
wp wpmudev posts-maintenance scan --post_types=post,page,product
```

Progress is streamed to the terminal and the command exits with a full summary.

## Dependency Guard

Some sites already load `google/apiclient`. We now detect the version that is active and pause our Google Drive features if a conflicting (< 2.15) build is found. An admin notice explains the situation so the site owner can upgrade the other plugin/theme. This avoids fatal errors while keeping Posts Maintenance fully operational.

### Forcing Google Client Support (advanced)

If you understand the risks and still want to run with an older Google client, flip the override flag in WordPress:

- Add `define( 'WPMUDEV_PLUGINTEST_FORCE_GOOGLE_CLIENT', true );` to `wp-config.php`, **or**
- Run `wp option update wpmudev_plugin_test_force_google_client 1` to toggle it at runtime.

Either approach (or the `wpmudev_plugin_test/google_client_force_enable` filter) will re-enable Drive tools while still surfacing a warning banner so you know an unsupported library is in play.

## Google Drive Configuration

1. Visit `WP Admin → Google Drive Test` (top-level menu) – this is the settings UI.
2. Paste your Google OAuth Client ID + Secret, then click **Save Credentials**. They are encrypted at rest.
3. Click **Authenticate with Google Drive** and finish the OAuth consent flow (use the redirect URI displayed within the UI when registering your app in Google Cloud Console).
4. Once authenticated you can upload files, create folders, or refresh your Drive listing directly from the same screen.

If you land on another admin page (e.g. Posts Maintenance) you can always jump back to the Drive UI via the "Open Google Drive Settings" button in the callout banner.
