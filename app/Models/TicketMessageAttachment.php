<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ticket_message_id
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size
 */
class TicketMessageAttachment extends Model
{
    protected $table = 'v2_ticket_message_attachment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = [
        'ticket_message_id',
        'original_name',
        'disk',
        'path',
        'created_at',
        'updated_at',
    ];
    protected $appends = ['name'];
    protected $casts = [
        'size' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id', 'id');
    }

    public function getNameAttribute(): string
    {
        return $this->original_name;
    }
}
