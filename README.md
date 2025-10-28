# WPMU DEV Plugin Test

> A feature-rich demonstration plugin used to assess WordPress & PHP development skills. Ships with a React-powered admin experience, hardened REST endpoints, cron-driven background workers, and CLI tooling.

---

## Contents

- [Features Overview](#features-overview)
  - [Google Drive Test Suite](#google-drive-test-suite)
  - [Posts Maintenance Automation](#posts-maintenance-automation)
  - [WP-CLI Commands](#wp-cli-commands)
  - [Dependency Guard](#dependency-guard)
- [Server-Side Requirements](#server-side-requirements)
- [Automation Configuration](#automation-configuration)
  - [Admin UI Workflow](#admin-ui-workflow)
  - [Cron History & Monitoring](#cron-history--monitoring)
  - [Troubleshooting Checklist](#troubleshooting-checklist)
- [Google Drive Configuration](#google-drive-configuration)
- [Development Workflow](#development-workflow)
  - [Composer](#composer)
  - [Node / Build Tasks](#node--build-tasks)
  - [Release Checklist](#release-checklist)
- [Project Structure](#project-structure)

---

## Features Overview

### Google Drive Test Suite

- Encrypted storage for Google OAuth credentials with a guided authentication flow.
- File and folder management (upload, download, rename, delete) through hardened REST endpoints.
- Responsive UI built with SUI components, including inline feedback, progress indicators, and i18n support.
- Automatic detection of conflicting `google/apiclient` packages with admin warnings and override flag.

### Posts Maintenance Automation

- Dedicated **Posts Maintenance** admin page seeded with React + Shared UI styling.
- Select post types, launch scans, monitor progress, cancel, or reset jobs.
- Background worker processes batches via WP-Cron while live data is persisted in `wpmudev_posts_maintenance_job`.
- Automation schedule lets you define up to 6 run times per day (site timezone) and keeps a 10-entry cron history for audits.
- Built-in recovery:
  - Stale `_transient_doing_cron` locks auto-clear when a scan starts or schedules a new worker.
  - Pending jobs requeue the worker hook on every `init`, so automation restarts even after hosting hiccups.
  - Batch size is stored per job to avoid premature completion when settings change mid-run.

### WP-CLI Commands

Run maintenance without the browser:

```bash
wp wpmudev posts-maintenance scan
wp wpmudev posts-maintenance scan --post_types=post,page,attachment
```

CLI scans stream progress and exit with a summary (total processed + elapsed time). They automatically bypass WP-Cron by processing inline.

### Dependency Guard

Conflicting Google API clients are detected. If a version `< 2.15` is already loaded, Drive features pause gracefully with an actionable notice. Advanced users can override by:

- Setting `define( 'WPMUDEV_PLUGINTEST_FORCE_GOOGLE_CLIENT', true );` in `wp-config.php`, or
- Running `wp option update wpmudev_plugin_test_force_google_client 1`, or
- Using the `wpmudev_plugin_test/google_client_force_enable` filter.

---

## Server-Side Requirements

WordPress launches background batches only when cron hooks execute. To keep the Posts Maintenance queue moving, schedule `wp cron event run --due-now` frequently. Example (once every 6 seconds using staggered jobs — replace `/path/to/wordpress` with the actual WP root):

```bash
* * * * * cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 6  && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 12 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 18 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 24 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 30 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 36 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 42 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 48 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
* * * * * sleep 54 && cd /path/to/wordpress && wp cron event run --due-now --allow-root >/dev/null 2>&1
```

**Guidelines**

- Keep `DISABLE_WP_CRON` unset/false so WordPress can spawn fallback runs during web requests.
- Tune frequency based on batch size; default batch size is 200 items (≈ 3,000 posts/minute with the above schedule).
- On Docker or containerized hosting, add the cron entries inside the container and ensure `cron`/`crond` is running.

---

## Automation Configuration

### Admin UI Workflow

1. Go to **WP Admin → Posts Maintenance**.
2. Choose target post types (default: posts & pages; attachments available if enabled).
3. Start a scan to process immediately (uses the current batch size).
4. Configure automation:
   - Toggle **Enable daily WP-Cron task**.
   - Optionally toggle **Run multiple times per day** and add up to six HH:MM slots. All times are stored in site timezone and displayed with am/pm.
   - Click **Save Automation Settings**.
5. Watch the dashboard:
   - Live progress updates (`Processed X of Y`).
   - Timeline + status badges (Pending / Running / Completed / Cancelled / Failed).
   - Recent WP-Cron Runs (last 10 executions with timestamps, slots, relative time, and processed totals).

### Cron History & Monitoring

- View the “Recent WP-Cron Runs” panel or inspect `wp option get wpmudev_posts_maintenance_cron_history --format=json`.
- The history only stores the 10 latest entries for clarity.

### Troubleshooting Checklist

| Symptom | Fix |
|---------|-----|
| Job stuck at “Queued” with 0 processed | Automatic unstick runs on every `init`. Ensure the server cron is firing (see [Server-Side Requirements](#server-side-requirements)). |
| Job finishes early | Let active jobs finish before changing batch size or cancel/reset before saving new settings. Each job now stores its own `per_page` value, but switching mid-run can still lead to partial results. |
| Automation runs missing | Review cron history; ensure `wp cron event run --due-now` is executing on schedule and `_transient_doing_cron` is not stuck. The plugin auto-clears stale locks (> 60s). |
| Want to run immediately | Use the CLI (`wp wpmudev posts-maintenance scan`) — it bypasses cron and processes inline. |

---

## Google Drive Configuration

1. Visit **WP Admin → Google Drive Test**.
2. Paste your OAuth Client ID/Secret, then click **Save Credentials** (encrypted at rest).
3. Click **Authenticate with Google Drive** and complete the consent flow. Use the redirect URI shown in the UI when configuring the Google Cloud project.
4. Manage Drive files/folders directly from the screen (upload, download, rename, delete).
5. Need to force support with an older Google client? Use the override mechanisms described in [Dependency Guard](#dependency-guard).

You can always return to the Drive UI via the “Open Google Drive Settings” link on other plugin pages.

---

## Development Workflow

### Composer

```bash
composer install
```

Run `composer install --no-dev` before packaging if you want a lean distributable without dev-only dependencies.

### Node / Build Tasks

Install JS dependencies:

```bash
npm install
```

| Command           | Description                                         |
|-------------------|-----------------------------------------------------|
| `npm run watch`   | Compile assets in development mode and watch files. |
| `npm run compile` | Produce production-ready assets (JS/CSS).           |
| `npm run build`   | Compile assets and stage a release bundle in `/build/`. |

### Release Checklist

1. `composer install --no-dev`
2. `npm run compile`
3. `npm run build`
4. Verify `/build/` includes only runtime code (PHP, compiled assets, translations, vendor autoloader).
5. Zip the build directory for distribution.

---

## Project Structure

```
app/                     # PHP domain logic (admin pages, REST controllers, CLI, etc.)
assets/                  # Compiled JS/CSS output for production
build/                   # Release-ready bundle after `npm run build`
core/                    # Loader/bootstrap classes
languages/               # Translation files
src/                     # React/SCSS source code
tests/                   # PHPUnit suite
wpmudev-plugin-test.php  # Plugin bootstrap
```

Supporting files (`webpack.config.js`, `babel.config.js`, `quest...`) live in the root to keep tooling self-contained.

---

Happy coding! Feel free to explore the codebase, run the tests, and tweak the automation to match real-world workloads. If you uncover improvements or issues, open a PR or document your findings. 🚀
