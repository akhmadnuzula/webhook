# Mini Webhook

Lightweight webhook receiver + viewer to quickly inspect HTTP requests. Generates a unique ID, stores each request as JSON under `storage/{id}`, and lets you browse + preview them in the browser.

Public repository: https://github.com/akhmadnuzula/webhook

## Features
- Unique per-session ID for endpoints
- Pretty URLs (`/hook/{id}`, `/view/{id}`) and query fallbacks
- JSON, form-data, and file uploads capture
- Inline preview under the selected row (dark theme)
- Auto-refresh list (2s) without losing your current preview
- No database; files stored under `storage/{id}`

## Requirements
- PHP 7.4+
- For pretty URLs in production: Apache with `.htaccess` or any web server routing to `index.php`

## Quick Start (Local)
Option A — PHP built-in server:

```
php -S 0.0.0.0:9000 index.php
```

Open `http://localhost:9000/` to get a new ID and open the viewer.

Optionally with auto-reload via nodemon:

```
nodemon --watch . --ext php --exec "php -S 0.0.0.0:9000 index.php"
```

## Deployment (Apache / cPanel)
- Upload the files to your web root or a subfolder.
- Ensure `.htaccess` is deployed. It routes all non-static requests to `index.php`.
- Visit your domain to get a new ID and use the pretty endpoints.

If rewrites are disabled, the app falls back to query endpoints automatically.

## Endpoints
- Receive (pretty): `/hook/{id}`
- View (pretty): `/view/{id}`
- Receive (fallback): `/webhook.php?id={id}`
- View (fallback): `/viewer.php?id={id}`

## Usage
1) Open Home and get an ID, e.g. `abc123`.
2) Send a webhook/test request to your Receive endpoint.

Example JSON request:
```
curl -X POST 'https://your-host/hook/abc123' \
  -H 'Content-Type: application/json' \
  -d '{"event":"ping","data":{"hello":"world"}}'
```

Example form-data with file upload:
```
curl -X POST 'https://your-host/hook/abc123' \
  -F 'note=hello' \
  -F 'file=@/path/to/file.txt'
```

3) Open the Viewer endpoint and click any row to preview its content inline.

## Storage Layout
- Requests: `storage/{id}/*.json`
- Uploads: `storage/{id}/uploads/*`

Each request JSON contains headers, method, path, query, parsed JSON (if any), form, files metadata, and raw body.

Note: `.gitignore` excludes `storage/` so payloads/uploads aren’t committed.

## How Routing Works
- `index.php` acts as a router and home page
  - `/hook/{id}` → `webhook.php?id={id}`
  - `/view/{id}` → `viewer.php?id={id}`
  - `/`, `/index.php`, `/new` → `home.php`
- `.htaccess` routes non-existing paths to `index.php` for Apache
- The viewer auto-detects when pretty URLs aren’t available and uses fallback query URLs

## Troubleshooting
- Preview clicks reload the entire page:
  - Ensure you are using `index.php` as the router with PHP built-in server: `php -S 0.0.0.0:9000 index.php`
- Auto-refresh doesn’t update on shared hosting:
  - Some hosts cache aggressively. The viewer sends no-cache headers and uses a fresh fetch each cycle.
  - Do a hard refresh once (Ctrl+F5 / Cmd+Shift+R).
- Permission issues writing to `storage/`:
  - Ensure the PHP process can create/write under `storage/`.

## Development Notes
- Primary files:
  - `index.php` — Router + 404 fallback
  - `home.php` — Landing page and ID generator
  - `webhook.php` — Receives and stores requests/uploads
  - `viewer.php` — Lists requests and renders previews (dark UI)
  - `.htaccess` — Routes to `index.php` on Apache
- No external dependencies; deploy anywhere PHP runs.

---
Enjoy! If you have ideas or issues, open an issue or PR in the public repo.
