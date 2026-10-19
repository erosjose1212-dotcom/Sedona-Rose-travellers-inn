<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    protected $fillable = [
        'name', 'id_type', 'id_number',
        'headcount', 'is_senior', 'is_pwd', 'contact',
    ];

    protected $casts = [
        'is_senior' => 'boolean',
        'is_pwd'    => 'boolean',
    ];

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function isEligibleForDiscount(): bool
    {
        return $this->is_senior || $this->is_pwd;
    }
}
