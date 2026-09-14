# Employee Call Monitor — InfinityFree deployment

1. Upload/extract the contents of this ZIP directly into `public_html`.
2. Open `https://callcentermonitoring.gt.tc/setup.php?key=KrK3_K18IaxpyWwxj12cptp-` once.
3. Create the manager account. The installer can create the tables even if the `_database/schema.sql` file cannot be read by the host, because a safe embedded schema fallback is included.
4. After successful installation, the installer creates `_database/install.lock` and refuses further installs with this key.
5. Open `https://callcentermonitoring.gt.tc/` to sign in.

MySQL host: `sql308.infinityfree.com`
MySQL database: `if0_42902064_callermonitor`
MySQL user: `if0_42902064`
Port: `3306`

Do not expose or share `api/config.php` or the installer key publicly. Change the MySQL password after deployment if the credentials have been exposed.


### QR FIX (2026-09-13)
The dashboard now loads app.v3.js with cache-busting and does not reference a global `QRCode` object. QR images are generated through direct image URLs with a fallback provider. If an old browser cache is present, the new filename prevents the old QRCode error from being reused.
