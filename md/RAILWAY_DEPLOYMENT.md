# Deploy on Railway

The app uses Apache/PHP in the root `Dockerfile`, a Railway MySQL service, and a Railway volume for uploaded files and database backups.

## 1. Create the services

1. In Railway, create a project and deploy the public GitHub repository.
2. Add a MySQL service to the same Railway project.
3. In the PHP app service's **Variables**, reference the MySQL service variables. Replace `MySQL` with the exact name of your database service if it is different:

   ```text
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   APP_ENV=production
   ```

4. Before the first deployment, add `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` to the PHP app service. Use your own administrator email and a unique password of at least 16 characters. Enter the password directly in Railway's Variables UI; never commit it or send it in chat.
5. Add a persistent volume to the PHP app service and mount it at `/data`. This preserves document uploads, branding images, profile photos, and backups across deployments.

## 2. Initialize the database

In the PHP app service's deployment settings, set the **Pre-deploy Command** to:

```text
sh scripts/initialize_railway_database.sh
```

The command creates the empty application schema on the first deployment, then runs the schema migration before the web service starts. The schema contains no seeded user accounts. The first migration uses `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` to create one administrator; it no longer creates accounts with shared default passwords. Keep the pre-deploy command configured for future deployments so new schema changes are applied before the app starts. Remove `INITIAL_ADMIN_PASSWORD` from the service variables after the first successful deployment.

## 3. Deploy and configure sign-in

1. Deploy the PHP app service. The container listens on Railway's assigned `PORT`.
2. In the app service's **Networking** settings, generate a public domain.
3. If Google sign-in is needed, add `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` as Railway variables. The redirect URI must be:

   ```text
   https://YOUR-RAILWAY-DOMAIN/auth/google_callback.php
   ```

   Add that exact URL to the OAuth client's authorized redirect URIs in Google Cloud.
4. For email notifications, add SMTP settings as Railway variables: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`, `FROM_EMAIL`, and `FROM_NAME`.

The app queues email instead of sending it during report submission. To deliver
queued notifications, create a second Railway service from the same repository
with the same MySQL and SMTP variables, set its start command to
`php app/workers/send_email_outbox.php`, and configure a cron schedule of
`* * * * *`. Do not attach the web service's `/data` volume to this worker.

## Notes

- This setup starts with an empty database and does not import local records.
- The app container's filesystem is temporary except for `/data`; keep the volume attached.
- Configure a regular off-platform backup for both the Railway database and the `/data` volume.
- OAuth and SMTP credentials that were previously hardcoded must be revoked or rotated before production use.
