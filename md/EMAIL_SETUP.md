# Gmail Setup for Client Account Notifications

## Step 1: Enable 2-Step Verification

1. Google Account → Security
2. 2-Step Verification → Enable

## Step 2: Generate App Password

1. Google Account → Security → App passwords
2. Select "Mail" → "Other" → "Report System"
3. **Copy 16-char password** (e.g. abcd efgh ijkl mnop)

## Step 3: Set Environment Variables (Terminal/Command Prompt)

If you want to open the login email on your phone, do not use `localhost` here. Use your computer's local network IP address instead.

```
set GMAIL_USER=yourgmail@gmail.com
set GMAIL_APP_PASSWORD=abcd efgh ijkl mnop
set FRONTEND_URL=http://192.168.1.55:3000
set EMAIL_FROM="Report System <yourgmail@gmail.com>"
```

> Tip: Run `ipconfig` and use the IPv4 address for your Wi-Fi or Ethernet adapter.
>
> Important: When using Gmail SMTP, `EMAIL_FROM` should match `GMAIL_USER` to avoid delivery problems.

## Step 4: Permanent Setup (.env or System)

**Option A - .env file** (recommended):
Create `.env` in project root:

```
GMAIL_USER=yourgmail@gmail.com
GMAIL_APP_PASSWORD=abcd efgh ijkl mnop
FRONTEND_URL=http://localhost:3000
EMAIL_FROM="Report System <yourgmail@gmail.com>"
```

**Update backend/main.py:**

```python
from dotenv import load_dotenv
load_dotenv()
```

**Install:** `pip install python-dotenv`

**Option B - Windows System Environment:**
Control Panel → System → Advanced → Environment Variables → New System Vars

## Step 5: Test

```
python backend/main.py
```

Admin create user → check console/email sent

**Troubleshoot:**

- Check console "[EMAIL ERROR]"
- Verify app password (no spaces)
- Test Gmail "Less secure apps" OFF (use app pass)
