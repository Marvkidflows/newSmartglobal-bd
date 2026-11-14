<?php
// TASK 4 — the ONLY place an article changes lifecycle state.
//
//   draft ──submit──▶ submitted ──approve──▶ approved ──publish/schedule──▶ published / scheduled ──unpublish──▶ unpublished
//     ▲                   └──reject (note required)──▶ rejected ──edit + submit again──▲
//
// * A contributor (news.contribute only) can never publish; their work must be approved by a DIFFERENT reviewer (news.review) first.
// * Nobody can approve their own submission.
// * A publisher (news.publish) may publish a draft directly only if the article's author is themselves a publisher (trusted author).
// * Media attached to an article must be approved before the article can go live; approving an article approves its attached media.
// * Atomic status transitions (UPDATE … WHERE status IN …) make double-clicks and concurrent actions safe.
namespace App\Services\News;

use App\Models\News\NewsArticle;
use App\Models\News\NewsMedia;
use App\Models\User;
use App\Services\RichTextSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NewsWorkflow
{
    public function __construct(protected RichTextSanitizer $sanitizer) {}

    protected function bad(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => $msg]);
    }

    protected function clean(array $d, User $actor): array
    {
        $out = [];
        foreach (['title', 'summary', 'content_type', 'category_id', 'visibility', 'cover_media_id', 'video_media_id'] as $k) {
            if (array_key_exists($k, $d)) $out[$k] = $d[$k];
        }
        if (isset($out['title'])) $out['title'] = trim(strip_tags($out['title']));
        if (!empty($out['summary'])) $out['summary'] = trim(strip_tags($out['summary']));
        if (array_key_exists('body', $d)) {
            $body = $this->sanitizer->sanitize((string) $d['body']);
            if ($body === '') $this->bad('body', 'The article body is empty or contains no permitted content.');
            $out['body'] = $body;
        }
        if (array_key_exists('is_featured', $d) && NewsPermissions::can($actor, 'news.publish')) $out['is_featured'] = (bool) $d['is_featured'];

        foreach (['cover_media_id' => ['image'], 'video_media_id' => ['video_file', 'video_embed']] as $field => $kinds) {
            if (empty($out[$field])) { if (array_key_exists($field, $out)) $out[$field] = null; continue; }
            $m = NewsMedia::find($out[$field]);
            if (!$m || !in_array($m->kind, $kinds, true)) $this->bad($field, 'Invalid media for this field.');
            $privileged = NewsPermissions::can($actor, 'news.edit') || NewsPermissions::can($actor, 'news.publish') || NewsPermissions::can($actor, 'news.review');
            if (!$privileged && (int) $m->uploaded_by !== (int) $actor->id) $this->bad($field, 'You can only attach media you uploaded.');
        }
        return $out;
    }

    public function create(User $actor, array $d, ?Request $req = null): NewsArticle
    {
        $attrs = $this->clean($d, $actor);
        $a = new NewsArticle($attrs);
        $a->forceFill([
            'status' => 'draft', 'author_id' => $actor->id, 'author_name' => mb_substr((string) ($actor->name ?? $actor->full_name ?? 'SSI Team'), 0, 120),
            'department' => NewsPermissions::departmentOf($actor),
        ]);
        DB::transaction(fn () => $a->save());
        NewsAudit::record($actor, 'article.created', $a, ['title' => $a->title, 'visibility' => $a->visibility], $req);
        return $a;
    }

    public function update(User $actor, NewsArticle $a, array $d, ?Request $req = null): NewsArticle
    {
        $own = (int) $a->author_id === (int) $actor->id;
        $editAny = NewsPermissions::can($actor, 'news.edit') || NewsPermissions::can($actor, 'news.publish');
        if (!$editAny && !($own && NewsPermissions::can($actor, 'news.contribute') && in_array($a->status, ['draft', 'rejected'], true))) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You may only edit your own drafts and rejected submissions.');
        }
        $a->fill($this->clean($d, $actor))->save();
        NewsAudit::record($actor, 'article.updated', $a, ['status' => $a->status], $req);
        return $a->refresh();
    }

    public function submit(User $actor, NewsArticle $a, ?Request $req = null): NewsArticle
    {
        if ((int) $a->author_id !== (int) $actor->id || !NewsPermissions::can($actor, 'news.contribute')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only the author can submit this article.');
        }
        $n = NewsArticle::whereKey($a->id)->whereIn('status', ['draft', 'rejected'])->update(['status' => 'submitted', 'review_note' => null, 'updated_at' => now()]);
        if ($n !== 1) $this->bad('status', 'Only drafts and rejected articles can be submitted.');
        NewsAudit::record($actor, 'article.submitted', $a, [], $req);
        return $a->refresh();
    }

    public function approve(User $reviewer, NewsArticle $a, ?string $note, ?Request $req = null): NewsArticle
    {
        $this->assertReviewer($reviewer, $a);
        $n = NewsArticle::whereKey($a->id)->where('status', 'submitted')->update([
            'status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note ? mb_substr(strip_tags($note), 0, 1000) : null, 'updated_at' => now(),
        ]);
        if ($n !== 1) $this->bad('status', 'Only submitted articles can be approved.');
        NewsMedia::whereIn('id', array_filter([$a->cover_media_id, $a->video_media_id]))->where('status', 'pending')
            ->update(['status' => 'approved', 'moderated_by' => $reviewer->id, 'moderated_at' => now()]);
        NewsAudit::record($reviewer, 'article.approved', $a, [], $req);
        return $a->refresh();
    }

    public function reject(User $reviewer, NewsArticle $a, string $note, ?Request $req = null): NewsArticle
    {
        $this->assertReviewer($reviewer, $a);
        if (trim($note) === '') $this->bad('note', 'Please tell the author what to change.');
        $n = NewsArticle::whereKey($a->id)->where('status', 'submitted')->update([
            'status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => mb_substr(strip_tags($note), 0, 1000), 'updated_at' => now(),
        ]);
        if ($n !== 1) $this->bad('status', 'Only submitted articles can be rejected.');
        NewsAudit::record($reviewer, 'article.rejected', $a, [], $req);
        return $a->refresh();
    }

    protected function assertReviewer(User $reviewer, NewsArticle $a): void
    {
        if (!NewsPermissions::can($reviewer, 'news.review')) throw new \Illuminate\Auth\Access\AuthorizationException('Reviewer permission required.');
        if ((int) $a->author_id === (int) $reviewer->id) $this->bad('status', 'You cannot review your own submission.');
    }

    /** $at null/past = publish now; future = schedule. */
    public function publish(User $actor, NewsArticle $a, ?Carbon $at = null, ?Request $req = null): NewsArticle
    {
        if (!NewsPermissions::can($actor, 'news.publish')) throw new \Illuminate\Auth\Access\AuthorizationException('Publisher permission required.');

        $trusted = $a->author && NewsPermissions::can($a->author, 'news.publish');
        $from = ['approved', 'unpublished', 'scheduled'];
        if ($trusted) $from = array_merge($from, ['draft', 'submitted', 'rejected']);
        if (!in_array($a->status, $from, true)) $this->bad('status', 'This article must be approved by a reviewer before it can be published.');

        foreach ([$a->cover_media_id, $a->video_media_id] as $id) {
            if ($id && NewsMedia::whereKey($id)->value('status') !== 'approved') $this->bad('media', 'Attached media is awaiting moderation.');
        }
        if (!$a->category_id) $this->bad('category_id', 'Choose a category before publishing.');

        $future = $at && $at->gt(now()->addMinute());
        $n = NewsArticle::whereKey($a->id)->whereIn('status', $from)->update($future
            ? ['status' => 'scheduled', 'scheduled_at' => $at, 'published_by' => $actor->id, 'updated_at' => now()]
            : ['status' => 'published', 'published_at' => now(), 'scheduled_at' => null, 'published_by' => $actor->id, 'updated_at' => now()]);
        if ($n !== 1) $this->bad('status', 'The article changed state; please reload.');
        NewsAudit::record($actor, $future ? 'article.scheduled' : 'article.published', $a, ['at' => $future ? $at->toIso8601String() : null], $req);
        return $a->refresh();
    }

    public function unpublish(User $actor, NewsArticle $a, ?Request $req = null): NewsArticle
    {
        if (!NewsPermissions::can($actor, 'news.publish')) throw new \Illuminate\Auth\Access\AuthorizationException('Publisher permission required.');
        $n = NewsArticle::whereKey($a->id)->whereIn('status', ['published', 'scheduled'])->update(['status' => 'unpublished', 'updated_at' => now()]);
        if ($n !== 1) $this->bad('status', 'Only live or scheduled articles can be unpublished.');
        NewsAudit::record($actor, 'article.unpublished', $a, [], $req);
        return $a->refresh();
    }

    public function remove(User $actor, NewsArticle $a, ?Request $req = null): void
    {
        $ownDraft = (int) $a->author_id === (int) $actor->id && in_array($a->status, ['draft', 'rejected'], true);
        if (!NewsPermissions::can($actor, 'news.manage') && !$ownDraft) throw new \Illuminate\Auth\Access\AuthorizationException('You cannot remove this article.');
        NewsAudit::record($actor, 'article.removed', $a, ['title' => $a->title], $req);
        $a->delete();                                                                   // soft delete: recoverable by an admin
    }

    /** Promotes scheduled articles whose time has come (also done on read, so no cron is strictly required for correctness). */
    public static function syncScheduled(): int
    {
        return NewsArticle::where('status', 'scheduled')->where('scheduled_at', '<=', now())->update([
            'status' => 'published', 'published_at' => DB::raw('scheduled_at'), 'updated_at' => now(),
        ]);
    }
}
