<?php

namespace App\Models;

use Database\Factories\AccountingExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingExport extends Model
{
    /** @use HasFactory<AccountingExportFactory> */
    use HasFactory;

    protected $fillable = ['business_day_id', 'version', 'destination', 'status', 'lines', 'configuration', 'sha256', 'created_by', 'reviewed_by', 'reviewed_at', 'delivered_at', 'delivery_reference', 'correction_of_id'];

    protected $casts = ['lines' => 'array', 'configuration' => 'array', 'reviewed_at' => 'datetime', 'delivered_at' => 'datetime'];
}
