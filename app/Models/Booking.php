<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'guest_name', 'guest_contact',
        'headcount', 'rate_tier', 'arrival_at', 'departure_at',
        'status', 'notes',
    ];

    protected $casts = [
        'arrival_at' => 'datetime',
        'departure_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if this booking conflicts with another date range on the same room.
     */
    public static function hasConflict(int $roomId, string $arrival, string $departure, ?int $excludeId = null): bool
    {
        return self::where('room_id', $roomId)
            ->where('status', 'scheduled')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('arrival_at', '<', $departure)
            ->where('departure_at', '>', $arrival)
            ->exists();
    }
}
