1. Unzip THIS zip into the ROOT of your original backend project (where artisan is). Replace all.
2. Run:  composer dump-autoload -o
3. Run:  php -l on changed files / php artisan test  (nothing has been executed yet)
Optional cleanup: delete app/Http/Controllers/Investor/"InvestorEmailController copy.php" (stray duplicate file).
Do NOT delete app/Http/Controllers/Marvflow/MarvflowMessageController.php - routes still use it.
No migration is run by this; run migrations yourself on staging only.
Left OUT on purpose (the corrected versions were OLDER than your original and would remove rich-text messaging / email protections):
MessageController, MarvflowInboxController, RichMessageBody, AdminCustomEmail, admin-custom.blade.php, EmailSanitiserReport, the two 2026_10_31 migrations.
RichTextSanitizer.php in this zip = YOUR original + only the new "external images dropped by default" change.
