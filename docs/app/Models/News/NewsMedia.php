<?php
// TASK 4 — an uploaded image / video file / secure video embed. Moderated: only `approved` media can appear on a published article.
namespace App\Models\News;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NewsMedia extends Model
{
    protected $table = 'news_media';
    protected $guarded = ['id'];
    protected $hidden = ['disk', 'path', 'sha256'];          // storage internals are never serialised
    protected $casts = ['moderated_at' => 'datetime'];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function isEmbed(): bool { return $this->kind === 'video_embed'; }
}
