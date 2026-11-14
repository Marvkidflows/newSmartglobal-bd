<?php
// LOCATION: app/Models/TeamRequestAttachment.php
//
// Metadata for a private document attached to a team request (message id NULL) or to one reply.
// The file itself lives on the private `team_documents` disk and is only served by authenticated controller routes.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamRequestAttachment extends Model
{
    protected $fillable = [
        'team_request_id', 'team_request_message_id', 'uploader_id',
        'original_name', 'storage_path', 'extension', 'mime_type', 'size', 'sha256',
    ];

    protected $casts = ['size' => 'integer'];

    // The storage path must never leave the API.
    protected $hidden = ['storage_path'];

    public function teamRequest()
    {
        return $this->belongsTo(TeamRequest::class);
    }

    public function message()
    {
        return $this->belongsTo(TeamRequestMessage::class, 'team_request_message_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
