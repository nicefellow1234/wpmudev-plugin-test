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

#### WP-CLI
Run the same maintenance routine without opening the admin UI:

```
wp wpmudev posts-maintenance scan
wp wpmudev posts-maintenance scan --post_types=post,page,product
```

Progress is streamed to the terminal and the command exits with a full summary.

## Dependency Guard

Some sites already load `google/apiclient`. We now detect the version that is active and pause our Google Drive features if a conflicting (< 2.15) build is found. An admin notice explains the situation so the site owner can upgrade the other plugin/theme. This avoids fatal errors while keeping Posts Maintenance fully operational.

## Google Drive Configuration

1. Visit `WP Admin → Google Drive Test` (top-level menu) – this is the settings UI.
2. Paste your Google OAuth Client ID + Secret, then click **Save Credentials**. They are encrypted at rest.
3. Click **Authenticate with Google Drive** and finish the OAuth consent flow (use the redirect URI displayed within the UI when registering your app in Google Cloud Console).
4. Once authenticated you can upload files, create folders, or refresh your Drive listing directly from the same screen.

If you land on another admin page (e.g. Posts Maintenance) you can always jump back to the Drive UI via the "Open Google Drive Settings" button in the callout banner.
