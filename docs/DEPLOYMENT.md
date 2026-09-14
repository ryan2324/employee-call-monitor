# Separate API backend deployment

## Why this exists
InfinityFree free hosting is suitable for the manager web pages but its browser-security layer blocks programmatic API clients such as Android/OkHttp. This backend must therefore run on an API-capable host.

## Recommended architecture
- Manager pages: keep on `https://callcentermonitoring.gt.tc`
- API: deploy this `backend` directory to an API-capable host (Docker supported).
- MySQL: use the API host's MySQL/private database or another database that permits the API server to connect.

## Required environment variables
Set these on the API service:
- `DB_HOST`
- `DB_PORT` (normally 3306)
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `WEB_ORIGIN=https://callcentermonitoring.gt.tc`
- `PUBLIC_API_BASE_URL=https://YOUR-API-DOMAIN.example.com/api/`
- `COOKIE_SECURE=true`
- `APP_TIMEZONE=Asia/Manila`
- `JOIN_TOKEN_MINUTES=10`
- `HEARTBEAT_TIMEOUT_SECONDS=45`
- `IDLE_THRESHOLD_SECONDS=300`
- `SESSION_NAME=employee_monitor_manager`
- `INSTALL_KEY=<random secret>`

## Database
Import `database/schema.sql` into the new MySQL database. Then migrate the existing `managers` row (and any data you want to retain) from the old database. Do not copy the old database password into source code.

## Health test
After deployment, open:
`https://YOUR-API-DOMAIN.example.com/api/health`

A healthy API returns JSON with `"ok":true` and `"database":true`.

## Manager website
Edit the `web/config.js` file in this package and set `API_BASE_URL` to the deployed API base URL, for example:
`https://api.example.com/api/`
Upload the updated `config.js` to the existing InfinityFree website.

## Android
Edit `android/EmployeeCallMonitor/app/src/main/java/com/example/employeecallmonitor/ApiConfig.kt` and set `BASE_URL` to the same API base URL. Build a new APK.

## Local test
From `backend/`:
`docker compose up --build`

Then open:
`http://localhost:8080/api/health`

The local compose database is initialized from `database/schema.sql`.
