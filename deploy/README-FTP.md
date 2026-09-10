BRVTAL CMS BACKEND / DEPLOYMENT PACK 01

REPLACE ONLY THE FILES INCLUDED IN THIS PACKAGE, PRESERVING YOUR REAL config/config.php.

Included:
- config/bootstrap.php
- config/logger.php
- config/.htaccess
- api/index.php
- api/health.php
- discadmin/index.php
- discadmin/technical.php
- discadmin/.htaccess
- database/.htaccess
- storage/.htaccess
- storage/rate_limits/.htaccess
- uploads/.htaccess

Do NOT delete existing files or folders.
Do NOT upload a config/config.php from another package.

Improvements in this pack:
- hardened admin sessions / CSRF / login rate limit
- secure upload and storage directory protections
- centralized API error handling
- richer dashboard endpoint with recent content in one request
- reduced dashboard API calls from 5 to 1
- dashboard thumbnails for recent events, artists, sets and media
- technical console and system diagnostics
- cache/no-store handling for diagnostics

After upload, verify:
1. /discadmin/
2. Login
3. Dashboard
4. TECHNICAL > API HEALTH
5. TECHNICAL > SYSTEM STATUS
6. TECHNICAL > SYSTEM LOGS
7. Public site

No database migration is required for this pack.
