# Manager website overlay

Upload these updated files to the existing InfinityFree `htdocs` website after setting the real API URL in `config.js`:

- `config.js` — API base URL
- `login.html`
- `dashboard.html`
- `calls.html`
- `diagnostic.html`
- `app.js` / `app.v3.js`
- `styles.css` / `index.php` as needed

The important change for a separate API is that CSV export and all AJAX requests use `APP_CONFIG.API_BASE_URL` instead of `/api/...` on the InfinityFree domain.
