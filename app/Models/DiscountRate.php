<?php

namespace App\Models;

use Database\Factories\DiscountRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountRate extends Model
{
    /** @use HasFactory<DiscountRateFactory> */
    use HasFactory;

    protected $fillable = ['discount_type', 'room_tier', 'duration', 'amount', 'updated_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
