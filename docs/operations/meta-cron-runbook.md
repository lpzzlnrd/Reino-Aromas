# Meta Cron + Queue Runbook (Production)

## 1) Required Environment

Set these variables in production:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `QUEUE_CONNECTION=database` (v1) or `redis` (recommended at scale)
- `META_APP_ID`
- `META_APP_SECRET`
- `META_WEBHOOK_VERIFY_TOKEN`
- `FACEBOOK_PAGE_ID`
- `FACEBOOK_PAGE_ACCESS_TOKEN`
- `FACEBOOK_REDIRECT_URI`

After updates:

```bash
php artisan config:cache
php artisan route:cache
php artisan optimize
```

## 2) Scheduler Activation

### Linux cron

Install one cron line:

```cron
* * * * * cd /var/www/reino-aromas && php artisan schedule:run >> /dev/null 2>&1
```

### Windows Task Scheduler

- Trigger: every 1 minute, indefinitely.
- Program: `php`
- Arguments: `artisan schedule:run`
- Start in: project root.

## 3) Queue Worker Activation

### Linux (Supervisor)

Example program:

```ini
[program:reino-aromas-queue]
command=php /var/www/reino-aromas/artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=2
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/www/reino-aromas/storage/logs/queue-worker.log
redirect_stderr=true
```

Apply:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start reino-aromas-queue:*
```

### Windows service style

Use NSSM or Task Scheduler to keep this command alive:

```powershell
php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=2
```

## 4) What Is Scheduled

- `meta:facebook:sync` every 5 minutes.
- `queue:prune-batches --hours=48` daily at `02:10`.

## 5) Verification Checklist

Run after deployment:

```bash
php artisan schedule:list
php artisan queue:work --once
php artisan meta:facebook:sync
```

Expected:

- `schedule:list` shows both tasks.
- webhook endpoint `POST /api/webhooks/meta` returns `202` on valid signature.
- new webhook events create/update `contacts`, `conversations`, and inbound `messages`.
- outbound Facebook sends are queued and then move message status `pending -> sent`.

## 6) Incident Playbook

### A) Queue stuck / backlog grows

1. Check worker process is alive.
2. Check `jobs` and `failed_jobs` tables.
3. Restart worker.
4. Retry failed jobs:

```bash
php artisan queue:retry all
```

### B) Webhook accepted but no messages persisted

1. Validate `X-Hub-Signature-256` and `META_APP_SECRET`.
2. Review `storage/logs/laravel.log` for `ProcessMetaWebhookJob failed`.
3. Inspect payload shape (`entry[].messaging[]`) from provider logs.

### C) Facebook outbound messages failing

1. Validate `FACEBOOK_PAGE_ID` and `FACEBOOK_PAGE_ACCESS_TOKEN`.
2. Check token expiration/permissions in Meta App dashboard.
3. Review `SendFacebookMessageJob failed` logs and `messages.failed_reason`.

### D) Scheduler not running

1. Check cron/task is installed with 1-minute cadence.
2. Execute `php artisan schedule:run` manually.
3. Inspect `storage/logs/scheduler-facebook-sync.log`.
