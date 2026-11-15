# File storage: what lives where, and how to keep uploads from being lost

## The problem
On Railway the container's own disk is **wiped on every redeploy**. Anything saved to a `local` disk disappears. Files kept on Cloudinary survive.

## What is stored where (audit of the backend)

| Upload | Where it goes | Survives a redeploy? |
|---|---|---|
| KYC document + selfie | Cloudinary (SDK) | Yes |
| MarvFlow request images, Dev-request images, Announcement images, Team request **images** | Cloudinary (SDK) | Yes |
| **News & Media** images and videos | disk named by `NEWS_MEDIA_DISK` | **Yes once set to `cloudinary`** (this delta) |
| Team request **documents** (PDF/DOCX/XLSX/TXT/MD) | disk named by `TEAM_DOCUMENTS_DISK` (default: local private) | **No** unless changed, see below |
| **Deposit proof screenshots** | hard-coded local `public` disk | **No** |
| Admin / Financial e-mail attachments | hard-coded local `public` disk (temporary, used to send the mail) | No (only needed briefly) |

## 1. Switch News & Media to Cloudinary
Set these on **Railway (Variables)** and in your local `.env`:

```
NEWS_MEDIA_DISK=cloudinary
CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>      # already used by KYC; must be present on Railway too
```
Then redeploy and run, inside the deployed service's shell:

```
php artisan files:verify-storage
```
It writes a tiny test image through the same calls the app uses, reads it back byte-for-byte, deletes it, and prints PASS/FAIL per disk. **Do not rely on Cloudinary until it prints PASS.**

## 2. Move News files that already exist
Rows remember their own disk, so old files keep working until moved. Run in the **deployed service** (the old files are in that container):

```
php artisan news:migrate-media --to=cloudinary --dry-run      # see what would move / what is already gone
php artisan news:migrate-media --to=cloudinary                # copy + verify SHA-256 + switch each row (originals kept)
php artisan files:verify-storage --check-rows                 # confirm every News file exists on its disk
php artisan news:migrate-media --to=cloudinary --delete-source   # optional, later, once the site looks right
```
A file that a previous redeploy already deleted shows as `MISSING` and must be re-uploaded; this command cannot recover it.

## What was fixed in the code
* Cloudinary rejected uploads made from the app's disk layer with "Invalid request parameters". A diagnostic showed that uploading from a real file path works but uploading a raw string or an in-memory stream does not (the stream reaches Cloudinary with no filename). All News uploads (images and videos) now go through one helper, `App\Support\DiskWriter`, which writes a real temporary file with the right extension, uploads from it, and always deletes it.
* A failed write used to leave a database row pointing at a file that was never stored. Now no row is created and the user sees "The file could not be saved".

## Things to know about Cloudinary for News media
* **Delivery is public.** Files get an unguessable 40-character name but there is no access check on Cloudinary's side; anyone who has the link can open it. The app's own draft / investor-only checks still decide who is *shown* the link.
* **Cost / rate limits.** Each view of a News image streams through the app and makes several Cloudinary Admin-API calls; heavy traffic can hit your plan's limit. Watch your Cloudinary usage.
* Plan limits: your News limits are 5 MB images and 50 MB videos (`NEWS_MAX_IMAGE_KB`, `NEWS_MAX_VIDEO_KB`). Check they are within your Cloudinary plan's upload limits.

## Not changed by this delta (decision needed)
* **Team documents.** Left on the private disk on purpose: the code states they must only be streamed after a per-request authorisation check, but Cloudinary delivery is public, and this setting has no per-file record, so switching it would orphan existing files. Cloudinary accounts can also block PDF/ZIP delivery by default. If these must survive redeploys, use a Railway Volume or a private S3 bucket. `files:verify-storage` already tests this disk too.
* **Deposit proof screenshots.** Saved to local public storage and shown by the Admin page as `/storage/<path>`. They will be lost on redeploy. Moving them touches financial evidence handling (backend + Admin/Financial screens), so it was not done here without your go-ahead.
