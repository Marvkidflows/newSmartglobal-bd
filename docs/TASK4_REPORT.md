# SSI Website — TASK 4 COMPLETION REPORT
## News & Media Publishing (public site + investor dashboard + admin CMS)

Separate deliverable from Task 3. **Independent of Task 3** (no shared code or tables; all tables `news_*`). Delivered as an overlay: new files + 5 tiny focused patches. Nothing from Task 2, Rank & Benefits, financial workflows or the existing `announcements` / News & Information Centre was modified.

---
## 0. READ THIS FIRST — what was and was not verified
| Item | Status |
|---|---|
| PHP (migration, models, services, controllers, routes, command) | **Written. NOT executed.** No PHP runtime / network in the build sandbox: `php -l`, `migrate`, PHPUnit were not run. |
| PHPUnit tests — **30 test methods** in 3 files | **Written, NOT executed. No pass/fail result is claimed.** |
| Static checks actually done | Brace/paren balance scan on every new PHP file (passed); manual review of every file. |
| Frontend | All new/changed JSX/JS **parsed OK** with Babel (syntax only). Full `vite build` **not possible** here (installed `node_modules` has Windows-only binaries). **Not opened in a browser.** |
| Real uploads with GD / finfo | Never exercised; image re-encode and MIME sniffing depend on PHP `gd` + `fileinfo` extensions being enabled on the server. |

**Please run before accepting:** `php artisan migrate && php artisan test --filter=News` and `npm run build`; send me the output and I will fix whatever surfaces (this was written blind).

---
## 1. What was built
* **News feed on the public site** (`/news`, `/news/:slug`) and **inside the investor dashboard** (`/investor/updates`) — responsive card grid, category chips, search, pagination, featured item, article page with cover image and video.
* **Three server-enforced audiences:** `public` (anyone) · `members` (any signed-in investor-role account, incl. unverified applicants) · `investor` (verified, eligible investors only: active + KYC verified + confirmed email). Draft / submitted / approved / rejected / unpublished / soft-deleted / not-yet-due content is never returned by any reader endpoint.
* **Content model:** title, summary, HTML body (sanitised), content type (article, announcement, market news, educational, video, promotion, investor notice), category (6 seeded, admin-editable), author + department, publication date, featured flag.
* **Images:** JPG/PNG/WebP only, ≤5 MB, ≤6000 px. Real MIME sniffed from content, `getimagesize` must succeed, extension must match, script/PHP/SVG markers rejected, re-encoded via GD (strips EXIF/polyglots) when available, stored under a random 40-char name on a **private** disk.
* **Video:** MP4/WebM upload ≤50 MB (container magic bytes checked) **or** secure embed. Embeds are **YouTube / Vimeo only, https only**; only provider + id are stored and the iframe URL is **rebuilt server-side** (`youtube-nocookie.com/embed/ID`, `player.vimeo.com/video/ID`) — arbitrary iframe HTML is never stored or rendered. Front-end iframe is sandboxed.
* **Secure serving:** files are never web-served directly. Public articles' media: stable route that re-checks "attached to a live public approved article" on every request. Members/investor articles' media: **short-lived (30 min) signed URLs** issued only inside responses the viewer is already authorised for. Headers: `nosniff`, restrictive CSP/sandbox, HTTP Range supported for local video.
* **Contributor submissions + moderation workflow** (see §6). Contributors can never publish; reviewers must be a different person than the author; media is moderated and an article can't go live with unapproved media; rejecting media automatically pulls any live article using it.
* **Scheduling:** publish at a future time; due items go live on read **and** via `news:publish-scheduled` (scheduler).
* **Admin CMS** (`/admin/news-media`): Articles list with filters, editor (upload cover / video / paste link, audience picker, schedule), Moderation queue, Media library, Categories, Permissions, Audit trail.
* **Audit** of every create/edit/submit/approve/reject/publish/schedule/unpublish/remove, media upload/moderation/deletion, category and permission changes (no bodies/secrets stored).

---
## 2. Complete new / changed file list
**NEW — backend**
```
config/news.php
database/migrations/2026_11_09_100001_create_news_media_tables.php
routes/news.php
app/Console/Commands/NewsPublishScheduled.php
app/Http/Middleware/RequireNewsPermission.php
app/Http/Controllers/News/{PublicNewsController,InvestorNewsController,NewsMediaController,ArticleAdminController,MediaAdminController,NewsAccessController}.php
app/Models/News/{NewsArticle,NewsCategory,NewsMedia,NewsPermission,NewsAuditLog}.php
app/Services/News/{NewsAudience,NewsAudit,NewsMediaService,NewsPermissions,NewsPresenter,NewsWorkflow}.php
tests/Feature/News/{NewsTestHelpers,NewsVisibilityTest,NewsWorkflowTest,NewsMediaTest}.php
```
**NEW — frontend**
```
src/services/newsApi.js
src/components/news/{NewsParts.jsx,news.css}
src/pages/public/NewsPage.jsx
src/pages/investor/InvestorUpdates.jsx
src/pages/admin/{AdminNewsMedia.jsx,AdminNewsMedia.css}
```
**CHANGED (shared; focused patches in /patches)**
```
backend/routes/api.php                    +1 line  (require __DIR__.'/news.php')
backend/routes/console.php                +1 scheduler line
frontend/src/App.jsx                      +3 imports, +5 routes
frontend/src/layouts/InvestorLayout.jsx   +1 nav item
frontend/src/layouts/AdminLayout.jsx      +1 nav item
```
**NOT touched:** `User.php`, `AppServiceProvider.php`, `bootstrap/app.php`, `src/services/api.js`, `Announcement` model/controllers/pages, Rank & Benefits, any financial file.

---
## 3. Database migration instructions
```
php artisan migrate        # 1 additive migration, no existing table altered
```
Creates `news_categories` (seeded: Company Updates, Market News, Educational Content, Announcements, Investor Notices, Promotions), `news_media`, `news_articles` (soft deletes), `news_permissions`, `news_audit_logs`.
**Rollback:** `php artisan migrate:rollback --step=1` drops only those 5 tables in FK-safe order. Uploaded files remain on disk (delete `storage/app/news-media` manually if desired).

**Environment (all optional):** `NEWS_MEDIA_DISK=local` (private disk; on Railway/ephemeral hosts point it at a persistent volume or S3-compatible disk), `NEWS_MAX_IMAGE_KB=5120`, `NEWS_MAX_VIDEO_KB=51200`. PHP extensions `fileinfo` (required) and `gd` (recommended) must be enabled. Check your web server / PHP `upload_max_filesize` & `post_max_size` ≥ 55 MB for video uploads, and any reverse-proxy body limit.

---
## 4. API endpoint documentation (prefix `/api`)
### Public (anonymous, 120 req/min)
| Endpoint | Notes |
|---|---|
| `GET /public/news?category=&type=&q=&featured=&page=&per_page=` | Live **public** articles only |
| `GET /public/news/{slug}` | 404 for anything not live+public |
| `GET /public/news/categories` | Active categories |
| `GET /public/news/media/{id}` | Only media attached to a live public approved article |
### Signed-in investors (`auth:sanctum, investor, check.account`)
| `GET /investor/news` · `GET /investor/news/{slug}` | Same filters. Visibility levels decided server-side: applicant → public+members; verified investor → +investor-only |
### Signed media
| `GET /news/media/{id}?signature=…&expires=…` | 30-minute signed URL; tampering → 403 |
### Staff (`/news-admin/…`, `auth:sanctum` + `staff.active` + permission)
| Endpoint | Permission |
|---|---|
| `GET articles` (filters `status,type,visibility,category_id,q,mine`) · `GET articles/{id}` | any news permission (contributors see **only their own**) |
| `POST articles` · `PUT articles/{id}` | contribute (own drafts / rejected only) · edit/publish (any) |
| `POST articles/{id}/submit` | author with contribute |
| `POST articles/{id}/approve` · `…/reject {note required}` | review (not the author) |
| `POST articles/{id}/publish {scheduled_at?}` · `…/unpublish` | publish |
| `DELETE articles/{id}` | manage, or author of own draft/rejected (soft delete) |
| `GET media` · `POST media/image` (multipart `file`,`alt?`) · `POST media/video` · `POST media/embed {url}` | any news permission (upload throttles: image 20/min, video 6/min) |
| `POST media/{id}/approve` · `…/reject` | review |
| `DELETE media/{id}` | owner or manage; 409 if used by a live article |
| `GET categories` | any · `POST/PUT categories` — manage |
| `GET audit` | manage |
| `GET/PUT permissions[/{user}]` | **admin only** |
Errors: 401 · 403 (no permission / not staff / not the author) · 404 (not visible to you — hidden items look non-existent) · 409 · 422 (validation, bad media, wrong workflow state) · 429.

---
## 5. Roles & permissions
| Permission | Meaning |
|---|---|
| `news.contribute` | Create/edit own drafts, upload own media, submit for review. **Cannot publish.** |
| `news.edit` | Edit any article |
| `news.review` | Approve/reject submissions and media (never your own) |
| `news.publish` | Publish, schedule, unpublish, feature. Media they upload is auto-approved |
| `news.manage` | Remove content, manage categories, read audit |
Admins hold all; only admins grant (Permissions tab). Other staff (financial, marvflow_*) have none by default — assign per departmental responsibility. Investors can never hold any (and receive 403 on `/news-admin/*`).

## 6. Moderation workflow
```
draft ─submit→ submitted ─approve→ approved ─publish→ published / scheduled ─unpublish→ unpublished
                   └─reject (note required)→ rejected ─edit + submit again─┘
```
* Contributor work needs approval by a **different** reviewer, then a publisher publishes it.
* A publisher may publish their *own* draft directly (trusted author); everyone else's must be approved first.
* Publishing requires a category and approved media. Approving an article approves its pending media.
* Status changes are atomic (`UPDATE … WHERE status IN …`) so double-clicks / concurrent clicks can't double-process.

---
## 7. Tests
**Written (30), none executed here:**
* `NewsVisibilityTest` (8): anonymous sees only live public; hidden states (draft/submitted/approved/rejected/unpublished/future-scheduled/soft-deleted); applicants vs verified investors; suspended/unconfirmed investor excluded; login/role required; scheduled due → live on read and via command; filters/search; payload leaks no storage internals or workflow fields.
* `NewsWorkflowTest` (12): full contributor→review→publish path with audit; no self-review; reject needs note + resubmit + lock while under review; contributors isolated; publisher direct publish / schedule / unpublish; category + approved-media gate; approval cascades to media; no attaching others' media; HTML sanitising + mass-assignment protection; soft-delete rules; access control & admin-only grants; invalid input.
* `NewsMediaTest` (10): valid image stored privately under random name; reviewer uploads auto-approved; disguised PHP/SVG/GIF/extension tricks/script-in-PNG rejected; size limits; video container/size checks; embed allow-list incl. 9 hostile URL forms and rebuilt iframe URL; public media route only for live public articles; signed URLs (tamper/unsigned/ineligible viewer); media moderation & deletion rules; upload endpoints need permission.

**Executed: none.** **Frontend syntax parse:** 8 files OK. **Passed tests claimed: 0.**

---
## 8. Known limitations / items needing management approval
1. **Not executed** (see §0).
2. **No separate "General User Dashboard" exists in this codebase.** The public feed is therefore on the public website (`/news`); signed-in non-verified applicants read public+members content via `/investor/updates` (the only logged-in area). If a distinct general-user dashboard is planned, mount `<NewsFeed service={investorNews} … />` there.
3. **Existing "News" (`/investor/news`, announcements-based News & Information Centre) was left untouched.** The new section lives at **`/investor/updates`**, so the investor menu currently shows both "News" and "News & Updates". Recommended follow-up (needs approval): migrate announcements into the new module and retire the old page.
4. **Storage:** default private local disk. On ephemeral hosts uploads are lost on redeploy unless `NEWS_MEDIA_DISK` points to persistent/object storage. Non-local disks stream through PHP without Range support (video seeking may be limited). No virus scanning, no image resizing/thumbnails, no video transcoding/poster images.
5. **Body editor** is an HTML textarea + sanitiser; no WYSIWYG, no inline images inside the body (use the cover image). The existing `RichComposer` was not reused to keep Task 4 independent.
6. UI is mounted in the **Admin** layout only. Non-admin staff granted permissions can use the API; adding the page to the Financial/MarvFlow layouts is a small patch left undone to avoid touching other teams' files (contributors/reviewers outside admin therefore have no screen yet — **decision needed on who the contributors are**).
7. No email/in-app notification to reviewers or authors on submit/approve/reject; the Moderation tab is the queue. No comments, likes, view counters or SEO meta tags.
8. Media moderation is per file; reusing one file in several articles is allowed. Replacing a rejected file requires uploading a new one.
9. A legitimate image whose compressed bytes contain the 5–7 byte text `<svg`/`<html`/`<script` by coincidence would be rejected (probability negligible; re-upload re-encoded).
10. YouTube/Vimeo embeds make third-party requests from the visitor's browser (privacy-enhanced `youtube-nocookie` domain is used); consider mentioning it in the privacy policy (**management/legal**).
11. Expiry/archival dates and content "removal" beyond soft-delete (restore UI) are not implemented; restore is possible only via database/artisan.

---
## 9. Shared-file conflict list
| File | Change | Risk |
|---|---|---|
| `routes/api.php` | append 1 `require` line | **Low**, but Task 3 appends its own line at the same spot → keep both (apply by hand if `git apply` complains) |
| `routes/console.php` | append 1 scheduler line | Same as above |
| `src/App.jsx` | +3 imports, +2 public routes after `/contact`, +2 investor routes after `news/:slug`, +1 admin route after `announcements` | Low–Medium |
| `src/layouts/InvestorLayout.jsx` | +1 nav item after "News" | Low |
| `src/layouts/AdminLayout.jsx` | +1 nav item after "Announcements" | Low–Medium (Task 3 adds items after "Email Center") |
| `User.php`, `AppServiceProvider.php`, `bootstrap/app.php`, `src/services/api.js` | **untouched** | None |
Task 3 & 4 share **no** code, tables, config or routes; they can be integrated in either order.

## 10. Integration instructions
1. Copy `backend/` and `frontend/` over the project roots (all new files).
2. Apply the 5 patches in `/patches` (or hand-edit; each is 1–8 added lines). Don't overwrite the whole shared files.
3. Ensure PHP `fileinfo` + `gd` extensions are enabled; set `NEWS_MEDIA_DISK` if needed; confirm upload size limits.
4. `php artisan migrate`
5. Make sure the scheduler runs (`schedule:run` every minute) — optional for correctness, since reads promote due items too.
6. Admin → **News & Media → Permissions**: grant staff what their department needs (e.g. contribute for team members, review for an editor, publish for a lead).
7. Run `php artisan test --filter=News` and `npm run build` and send me the output.

---
**Integration note (superseded statements).** After the Tasks 2–5 integration: the investor news experience is `/investor/news` (this module) with the original announcements kept at `/investor/announcements`; `/investor/updates*` redirects to `/investor/news`. The admin UI is also available to delegated staff at `/{financial|marvflow|registration}/news`, showing only the actions their grants allow. See `docs/integration/`.
