<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = ['folio_id', 'order_id', 'number', 'business_date', 'document', 'user_id'];

    protected $casts = ['document' => 'array', 'business_date' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Posted records are immutable.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Posted records cannot be deleted.');
        });
    }
}
