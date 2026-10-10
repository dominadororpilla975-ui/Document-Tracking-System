# Configure message email delivery

The application uses PHPMailer and requires an existing sender mailbox with SMTP access. It cannot create an email mailbox for you. Configure the sender account on the server; do not put its password in source code or send it in chat.

## Gmail setup

1. Use the Gmail account that should send system notifications.
2. Enable 2-Step Verification for that account and create a Google App Password. Use the app password, not the account's normal password.
3. Copy `config/mail.local.php.example` to a PHP configuration file outside the web root, for example `C:\xampp\private\report-tracking-mail.php`.
4. Edit that external file with the sender email and app password.
5. Configure the web server to set `REPORT_TRACKING_MAIL_CONFIG` to the external file path.

   For PHP's built-in server, set it in the same PowerShell terminal before starting the server:

   ```powershell
   $env:REPORT_TRACKING_MAIL_CONFIG = 'C:\xampp\private\report-tracking-mail.php'
   php -S localhost:8000
   ```

   Stop any existing `php -S` process with Ctrl+C and start it again from that terminal after changing the configuration. The server inherits environment variables when it starts.

   For Apache, add this to the relevant Apache `<VirtualHost>` configuration, then restart Apache:

   ```apache
   SetEnv REPORT_TRACKING_MAIL_CONFIG "C:/xampp/private/report-tracking-mail.php"
   ```

6. Ensure the account running the web server can read the external configuration file.
7. Send a test message through the system and verify delivery to the recipient's inbox or spam folder.

The Gmail account in `smtp_user` must be the account whose App Password is in `smtp_pass`, and `from_email` should match that same Gmail address. For other SMTP providers, replace `smtp_host`, `smtp_port`, and `smtp_secure` with that provider's SMTP settings. Port 587 normally uses `tls`; port 465 normally uses `ssl`.

SMTP credentials are loaded from the external file or from server environment variables (`SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`, `SMTP_PORT`, `FROM_EMAIL`, and `FROM_NAME`). Environment variables take precedence over the external file. If you set any of these variables in the terminal, start `php -S` from that same terminal; variables set in another terminal are not inherited.
