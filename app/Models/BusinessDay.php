<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\BusinessDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessDay extends Model
{
    public function setBusinessDateAttribute(string|CarbonInterface $value): void
    {
        $this->attributes['business_date'] = Carbon::parse($value)->toDateString();
    }

    /** @use HasFactory<BusinessDayFactory> */
    use HasFactory;

    protected $fillable = ['business_date', 'status', 'version', 'snapshot', 'closed_by', 'closed_at'];

    protected $casts = ['business_date' => 'date', 'snapshot' => 'array', 'closed_at' => 'datetime'];
}
