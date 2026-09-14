# Render + Neon PostgreSQL deployment

This version is converted from MySQL to PostgreSQL and is intended for a separate API backend.

## Architecture

- Manager website: keep `https://callcentermonitoring.gt.tc` on InfinityFree.
- API: deploy `backend/` as a Render Web Service using Docker.
- Database: use a Neon PostgreSQL database.
- Android: point `ApiConfig.kt` at the Render API URL.

InfinityFree is no longer used for API requests from Android.

## 1. Create the Neon database

Create a Neon PostgreSQL project/database. In the Neon dashboard, copy the connection details or connection string.

The API accepts either:

- `DATABASE_URL=postgresql://USER:PASSWORD@HOST/DBNAME?sslmode=require`
- or the individual `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE` variables.

## 2. Create the tables

Open the Neon SQL Editor and paste the complete contents of:

`database/schema.sql`

Run it once.

## 3. Deploy the API to Render

Create a **Web Service**, choose your repository/ZIP contents through your normal Git workflow, and use Docker. Set the service's root directory to `backend` if the repository contains the full project.

Recommended region: the same region as your Neon project.

No port setting is normally required for the Dockerfile because Apache listens on port 80.

## 4. Environment variables

Set:

- `DATABASE_URL` = Neon connection string
- `WEB_ORIGIN` = `https://callcentermonitoring.gt.tc`
- `PUBLIC_API_BASE_URL` = your Render API URL ending in `/api/`
- `COOKIE_SECURE` = `true`
- `APP_TIMEZONE` = `Asia/Manila`
- `JOIN_TOKEN_MINUTES` = `10`
- `HEARTBEAT_TIMEOUT_SECONDS` = `45`
- `IDLE_THRESHOLD_SECONDS` = `300`
- `SESSION_NAME` = `employee_monitor_manager`
- `INSTALL_KEY` = a long random secret

If `DATABASE_URL` is present, it takes precedence over the individual DB variables.

## 5. Test the API

Open:

`https://YOUR-RENDER-SERVICE.onrender.com/api/health`

A healthy response should contain JSON similar to:

`{"ok":true,"database":true,...}`

## 6. Create the manager account

For the first deployment, open:

`https://YOUR-RENDER-SERVICE.onrender.com/setup.php`

Enter the same manager email/password you want to use on the existing manager website, plus the `INSTALL_KEY`.

The page creates or updates the manager account using a secure password hash.

**After successful setup, remove `setup.php` from the deployed project and redeploy.**

## 7. Point the InfinityFree website to the new API

Edit `web/config.js`:

`window.APP_CONFIG={API_BASE_URL:'https://YOUR-RENDER-SERVICE.onrender.com/api/'};`

Upload that updated `config.js` to the existing InfinityFree website.

## 8. Point Android to the new API

Edit:

`android/EmployeeCallMonitor/app/src/main/java/com/example/employeecallmonitor/ApiConfig.kt`

Set `BASE_URL` to the same Render API URL ending in `/api/`.

Build a new APK and install it on the employee phone.

## 9. Important session/CORS requirements

The API is configured for the InfinityFree manager origin and cross-origin credentials. Keep:

- `WEB_ORIGIN=https://callcentermonitoring.gt.tc`
- `COOKIE_SECURE=true`

Do not use `*` for the allowed origin.

## 10. Existing data

This package creates a new PostgreSQL database. It does not automatically copy the old InfinityFree MySQL data.

For a fresh installation, use `setup.php` to recreate the manager account and then reconnect employee phones with new join tokens.

If you need the old employees/call history preserved, export the old MySQL tables and perform a one-time migration into the PostgreSQL schema before using the new API.

## Local Docker test

From `backend/`:

`docker compose up --build`

Then open:

`http://localhost:8080/api/health`

The local PostgreSQL database is initialized from `database/schema.sql`.
