# Document-Tracking-System

Municipal Document Tracking System built with PHP, Apache, and MySQL/MariaDB.

## Deploy on Railway

See the [Railway deployment guide](md/RAILWAY_DEPLOYMENT.md) for the Docker
setup, database configuration, persistent upload storage, and secure first-admin
initialization. The deployment starts with a new, empty database; it does not
import local user or report data.

Before production, rotate the previously exposed Google OAuth and SMTP
credentials and configure replacements as Railway environment variables.