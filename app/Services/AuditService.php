<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditService
{
    /** @param array<string, mixed> $values */
    public function record(Request $request, string $event, Model $source, array $values): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'event' => $event,
            'auditable_type' => $source::class, 'auditable_id' => $source->id,
            'new_values' => json_encode($values, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
