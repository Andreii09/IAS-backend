# Deploying the Bantay Cordova backend

The API is a Laravel 12 app. This repo contains a production Docker image and a Render blueprint; the same image runs on Railway, Fly.io or any Docker host.

Frontend deployment files are in the separate repo: `https://github.com/Andreii09/IAS-frontend`.

## What is included

- `Dockerfile` — PHP 8.3-FPM, Nginx, Supervisor, queue worker and scheduler
- `docker/nginx.conf.template` — serves `public/` and forwards PHP to FPM
- `docker/entrypoint.sh` — renders the Nginx config, runs migrations (opt-in), caches Laravel config, starts Supervisor
- `render.yaml` — Render blueprint for the web service
- `.dockerignore` — keeps `.env`, `vendor/` and caches out of the image

## Before you start

You need:

1. A **MySQL or PostgreSQL database reachable from the internet**. This repo's image includes both `pdo_mysql` and `pdo_pgsql`. Render does not provide free MySQL, so use an external provider (Aiven, Railway MySQL, etc.) or a Postgres URL.
2. A free Gemini API key from [Google AI Studio](https://aistudio.google.com/apikey).
3. The **frontend public hostname**. The browser only talks to the frontend; the frontend Nginx proxies `/api` and `/sanctum` to this backend. Sanctum must know that hostname.

## Deploy to Render (recommended)

1. Push this repo to GitHub.
2. In Render, choose **Blueprint → New Blueprint Instance** and select this repository.
3. Render reads `render.yaml`. Fill in the `sync: false` values:

   | Key | Value |
   |---|---|
   | `APP_URL` | `https://<your-frontend-host>` |
   | `FRONTEND_URL` | `https://<your-frontend-host>` |
   | `DATABASE_URL` | `mysql://user:pass@host:3306/bantay_cordova` (or `postgres://…`) |
   | `SESSION_DOMAIN` | `<your-frontend-host>` |
   | `SANCTUM_STATEFUL_DOMAINS` | `<your-frontend-host>` |
   | `GEMINI_API_KEY` | your free key |
   | `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD` | seeded admin credentials |

   The blueprint defaults `DB_CONNECTION` to `pgsql` (Render has no free MySQL). Change it to `mysql` only if your `DATABASE_URL` points at MySQL/MariaDB.

   `SESSION_DOMAIN` and `SANCTUM_STATEFUL_DOMAINS` use **bare hostnames** — no `https://` and no port. Multiple hostnames can be comma-separated.

4. For the first deploy only, set `RUN_SEED=true` so the admin account is created. After that deploy succeeds, set it back to `false` to avoid duplicate seed data.
5. Deploy. Check `https://<your-backend-host>/api/health` — it should return JSON with `"status": "ok"`.

Then deploy the frontend repo and set its `API_ORIGIN` to `https://<your-backend-host>` (no trailing slash).

## Local Docker test

```powershell
docker build -t bantay-cordova-api .
docker run --rm -p 8000:80 `
  -e APP_KEY=base64:YOUR_KEY `
  -e APP_ENV=production `
  -e APP_URL=http://localhost:8000 `
  -e FRONTEND_URL=http://localhost:5173 `
  -e DATABASE_URL=mysql://root@host.docker.internal:3306/bantay_cordova `
  -e SESSION_DOMAIN=localhost `
  -e SANCTUM_STATEFUL_DOMAINS=localhost:5173 `
  -e GEMINI_API_KEY=YOUR_KEY `
  bantay-cordova-api
```

Generate an `APP_KEY` with:

```powershell
$env:Path='C:\xampp\php;'+$env:Path; php artisan key:generate --show
```

## Verify after deployment

```bash
curl https://<your-backend-host>/api/health
```

Sign in from the deployed frontend, open the assistant, and send a question. If Gemini is unavailable, the UI shows a labelled **Offline help** answer instead of failing.

## Secrets and data

- Never commit `.env`. The Gemini key, admin password and database URL only exist as platform environment variables.
- The database is **not** in Git; `RUN_MIGRATIONS=true` applies the committed migrations on deploy.
- `vendor/` is installed during the Docker build, not committed.
- On Render's free plan, an idle service spins down; the first request after inactivity can take a few seconds.
