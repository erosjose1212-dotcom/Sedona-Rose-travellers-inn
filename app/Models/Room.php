<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'number', 'floor', 'name', 'type', 'status',
        'is_staff_quarters',
        'base_rate_3h', 'base_rate_4h', 'base_rate_6h', 'base_rate_12h',
        'base_rate_24h', 'base_rate_promo',
        'notes',
    ];

    protected $casts = [
        'is_staff_quarters' => 'boolean',
        'base_rate_3h' => 'decimal:2',
        'base_rate_4h' => 'decimal:2',
        'base_rate_6h' => 'decimal:2',
        'base_rate_12h' => 'decimal:2',
        'base_rate_24h' => 'decimal:2',
        'base_rate_promo' => 'decimal:2',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function activeFolio(): HasOne
    {
        return $this->hasOne(Folio::class)
            ->where('status', 'active')
            ->ofMany(['id' => 'max'], fn (Builder $query): Builder => $query->where('status', 'active'));
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isOccupied(): bool
    {
        return $this->status === 'occupied';
    }

    public function getRateForTier(string $tier): float
    {
        return match ($tier) {
            '3h' => (float) $this->base_rate_3h,
            '4h' => (float) $this->base_rate_4h,
            '6h' => (float) $this->base_rate_6h,
            '12h' => (float) $this->base_rate_12h,
            '24h' => (float) $this->base_rate_24h,
            'promo' => (float) $this->base_rate_promo,
            default => 0.0,
        };
    }

    public function getTierHours(string $tier): int
    {
        return match ($tier) {
            '3h' => 3,
            '4h' => 4,
            '6h' => 6,
            '12h' => 12,
            '24h' => 24,
            'promo' => 10,
            default => 0,
        };
    }

    public function getCheckoutForTier(string $tier, CarbonInterface $arrival, int $extraHours = 0): CarbonInterface
    {
        if ($tier !== 'promo' || $this->is_staff_quarters) {
            return $arrival->copy()->addHours($this->getTierHours($tier) + $extraHours);
        }

        $hotelArrival = $arrival->copy()->setTimezone('Asia/Manila');
        $checkout = $hotelArrival->copy()->startOfDay()->setHour(6);
        if ($hotelArrival->hour >= 6) {
            $checkout = $checkout->addDay();
        }

        return $checkout->addHours($extraHours)->setTimezone($arrival->getTimezone());
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeOrderByNumber(Builder $query): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('number'));
        $castType = in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'UNSIGNED' : 'INTEGER';

        return $query->orderByRaw("CAST({$column} AS {$castType})")->orderBy('number');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeByFloor($query, int $floor)
    {
        return $query->where('floor', $floor);
    }

    /**
     * Retrieve or auto-create an active Staff Folio for Employee Quarters orders.
     */
    public function getOrCreateStaffFolio(): Folio
    {
        return DB::transaction(function (): Folio {
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $folio = Folio::where('room_id', $this->id)->where('status', 'active')->lockForUpdate()->first();
            if ($folio) {
                return $folio;
            }

            $guest = Guest::firstOrCreate(
                ['contact' => 'STAFF-QUARTERS-12'],
                ['name' => 'Permanent Employee Quarters', 'headcount' => 2, 'is_senior' => false, 'is_pwd' => false]
            );

            return Folio::create([
                'room_id' => $this->id,
                'guest_id' => $guest->id,
                'user_id' => Auth::id() ?? 1,
                'transaction_id' => 'STAFF-'.$this->number.'-'.strtoupper(Str::random(12)),
                'rate_tier' => 'promo',
                'checked_in_at' => now(),
                'expected_checkout_at' => now()->addYear(),
                'status' => 'active',
                'room_charge' => 0.00,
                'surcharge_total' => 0.00,
                'pos_total' => 0.00,
                'gross_total' => 0.00,
                'net_total' => 0.00,
                'pos_items' => [],
            ]);
        });
    }
}
