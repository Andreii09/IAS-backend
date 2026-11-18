# Bantay Cordova — AI knowledge base (factual, injected into prompts)

## Product

Bantay Cordova is a community news and information platform for Cordova residents: a website for reading and publishing local news, announcements and advisories. It is built with Laravel 12 (backend/API) and React (single-page app). The assistant uses Google Gemini, called only from the Laravel backend. XAMPP is used for local development only; it is not the production host.

## Screens and routes

The React app uses the paths in the UI column; the API column is the verified backend endpoint.

| # | Screen | UI | API |
|---|--------|----|-----|
| 1 | Home / news feed | `/` | `GET /api/news` |
| 2 | Story detail | `/news/{id}` | `GET /api/news/{id}` |
| 3 | Sign in | `/login` | `POST /api/login` |
| 4 | Register | `/register` | `POST /api/register` |
| 5 | Forgot password | `/forgot-password` | `POST /api/forgot-password` |
| 6 | Reset password | `/reset-password/{token}` | `POST /api/reset-password` |
| 7 | Manage stories (editor/admin) | `/manage` | `POST /api/news`, `PUT /api/news/{id}`, `DELETE /api/news/{id}` |
| 8 | Admin dashboard and users | `/admin` | `GET /api/admin/overview`, `GET /api/admin/users`, `PATCH /api/admin/users/{id}` |
| 9 | Admin audit log | `/admin/audit` | `GET /api/admin/audit` (plus export and verify) |
| 10 | Admin settings, tokens, AI usage, security, revisions | `/admin/*` | `/api/admin/settings`, `/api/admin/tokens`, `/api/admin/ai-usage`, `/api/admin/security`, `/api/admin/content/revisions` |
| 11 | AI assistant (chat widget) | available across the app | `POST /api/chat` |

Supporting endpoints: `GET /api/me`, `POST /api/logout`, `POST /api/change-password`, `GET /api/news/{id}/revisions`, `POST /api/news/{id}/restore` (admin only) and `GET /api/health`.

## Roles and permissions

| Capability | reader | editor | admin |
|---|---|---|---|
| Read published news (subject to visibility) | yes | yes | yes |
| Use the AI assistant | yes | yes | yes |
| Create stories | no | yes | yes |
| Edit/delete own stories | no | yes | yes |
| Edit/delete any story | no | no | yes |
| Manage users and roles | no | no | yes |
| Manage API tokens | no | own only | all |
| View audit log / AI usage / settings | own actions only | own actions only | all |

## Stories: status and visibility

- **Draft** — unpublished; visible only to its author, editors and admins.
- **Published** — live now and visible according to its visibility setting.
- **Scheduled** — queued for automatic publication at its `published_at` date/time.
- **Public** — visible to everyone, including visitors who are not signed in.
- **Residents** — visible to any signed-in user; anonymous visitors cannot open such a story.

## Creating, editing and deleting a story

Editors and admins open **Manage stories**, choose **Create story** or **Edit** on a row, then supply the title, category, summary, body, status and visibility and save. To delete a story the user must tick the required confirmation checkbox on the manage screen (acknowledging that the deletion is permanent) and then confirm Delete. Story edits and deletions are recorded in the audit log, and revisions are kept for recovery.

## Accounts and sign-in flows

- **Sign in** with the registered email address and password; the first sign-in of a seeded admin forces a password change.
- **Register** creates a reader account. Choosing "editor request" records a requested role; an administrator approves it by changing the role in the admin users screen.
- **Forgot password** sends a reset link to the frontend reset form; follow the emailed link to choose a new password.
- **Sign out** with `POST /api/logout` to end the session.

## API tokens

Personal access tokens are managed on the **Tokens** screen under Administration (`/admin/tokens`, API `/api/admin/tokens`). Grant only the least-privilege abilities needed: `news:read` for a read-only integration and `news:write` only where publishing is required. The plaintext token is displayed once at creation; only its hash is stored, and it can be revoked at any time. Never share a token or paste one into chat.

## Audit log and hash chain

Significant actions (sign-ins, story create/edit/delete, registration approvals, role changes, settings changes and flagged AI chats) are written to the audit log with actor, action, entity, before/after values and timestamp. Each entry stores a fingerprint derived from its content plus the previous entry's fingerprint, forming a chain; `php artisan audit:verify` reports the first broken row. Only admins can view all entries; other roles can see their own actions.

## AI assistant behaviour

- The assistant is **read-only**: it explains Bantay Cordova information and published stories but cannot create, edit, delete or approve anything.
- It receives the signed-in user's role and up to five matching published stories that user may see; readers and editors never receive drafts, emails, audit data or admin statistics.
- Conversations are logged for accountability; messages containing personal data or suspicious instructions are flagged for admin review. Personal data is masked before storage.
- Each role has a daily token budget (reader 20,000; editor 40,000; admin 60,000) with an admin override, plus a per-user request limit. When the budget is spent the assistant asks the user to try again tomorrow.
- Do not share passwords, API tokens, other users' data or private configuration with the assistant. If the answer is not in the supplied knowledge, it says "I don't know".

## Help and contact

For access problems, incorrect announcements or account questions, contact the **barangay hall** or the **site administrator** (contact details are a placeholder to be supplied by the administrator).
