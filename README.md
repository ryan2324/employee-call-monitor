# Employee Call Monitor — Render + Neon PostgreSQL

This package is the PostgreSQL/Neon version of the separate API backend.

## Recommended architecture

- **InfinityFree:** manager website only
- **Render:** PHP API Web Service (Docker)
- **Neon:** PostgreSQL database
- **Android:** connects directly to the Render API

This avoids InfinityFree's programmatic-client/browser-security limitation.

## Package layout

- `backend/` — PostgreSQL-compatible PHP API and Docker deployment files
- `backend/database/schema.sql` — Neon PostgreSQL schema
- `backend/setup.php` — one-time manager setup page; remove it after use
- `android/EmployeeCallMonitor/` — Android project configured for a separate API URL
- `web/` — manager website files configured for a separate API URL

## Quick deployment order

1. Create a Neon PostgreSQL database.
2. Run `backend/database/schema.sql` in Neon SQL Editor.
3. Deploy `backend/` as a Render Web Service using Docker.
4. Add the Render environment variables from `backend/docs/DEPLOYMENT.md`.
5. Test `/api/health`.
6. Use `/setup.php` once to create/update the manager account, then remove it.
7. Set `web/config.js` to the Render API URL and upload it to InfinityFree.
8. Set Android `ApiConfig.kt` to the Render API URL and build the new APK.

## Security note

Do not put database passwords or the `INSTALL_KEY` into source files committed to a public repository. Use Render environment variables and Neon connection secrets.
