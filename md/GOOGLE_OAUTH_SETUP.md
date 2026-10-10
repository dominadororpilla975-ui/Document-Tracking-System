# Google sign-in setup

Google requires the `redirect_uri` in each authorization request to exactly match an authorized redirect URI on the OAuth client. Scheme, hostname, port, and path must all match.

## Configure the application

Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in the PHP server environment. The application derives the callback path from the active script, so it works when installed at the domain root or in a subdirectory. For example:

- App at `http://localhost:8000`: `http://localhost:8000/auth/google_callback.php`
- App at `http://localhost/report-tracking-system`: `http://localhost/report-tracking-system/auth/google_callback.php`
- App at `https://reports.example.com`: `https://reports.example.com/auth/google_callback.php`

If the public URL differs from what PHP detects (such as HTTPS terminated by a reverse proxy), explicitly set `GOOGLE_REDIRECT_URI` to the public callback URL. Use the same value for the authorization request and token exchange; the application does this automatically.

## Authorize the callback in Google Cloud

1. Open the OAuth 2.0 client used by this application in Google Cloud Console.
2. Under **Authorized redirect URIs**, add the exact callback URL for the deployed installation.
3. Save the client, then retry sign-in.

Do not include a trailing slash unless the callback URL itself has one. Each scheme, hostname, port, and path is distinct; for example, `http://localhost/...` does not match `http://localhost:8000/...`.
