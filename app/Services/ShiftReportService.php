<?php

namespace App\Services;

use App\Models\Folio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ShiftReportService
{
    /** @return array{date: string, shift: string, day: CarbonImmutable, start: CarbonImmutable, end: CarbonImmutable, label: string} */
    public function period(?string $date = null, string $shift = 'all'): array
    {
        $date ??= CarbonImmutable::now('Asia/Manila')->subMinutes(390)->toDateString();
        $day = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
        $start = $day->setTime(6, 30);
        if ($shift === 'night') {
            $start = $start->addHours(12);
        }
        $end = $start->addHours($shift === 'all' ? 24 : 12);
        $label = match ($shift) {
            'day' => 'Day shift',
            'night' => 'Night shift',
            default => 'Both shifts',
        };

        return compact('date', 'shift', 'day', 'start', 'end', 'label');
    }

    /** @return array{date: string, shifts: array<string, array<string, mixed>>} */
    public function activity(?string $date = null): array
    {
        $date = $this->period($date)['date'];
        $shifts = [];
        foreach (['day', 'night'] as $shift) {
            $period = $this->period($date, $shift);
            $shifts[$shift] = $period + [
                'check_ins' => $this->counts($period['start'], $period['end'], 'checked_in_at'),
                'check_outs' => $this->counts($period['start'], $period['end'], 'checked_out_at'),
            ];
        }

        return compact('date', 'shifts');
    }

    /** @return array{stays: int, guests: int} */
    private function counts(CarbonImmutable $start, CarbonImmutable $end, string $timestamp): array
    {
        $counts = Folio::where('folios.status', '!=', 'voided')
            ->when($timestamp === 'checked_out_at', fn (Builder $query): Builder => $query->where('folios.status', 'checked_out'))
            ->whereHas('room', fn (Builder $query): Builder => $query->where('is_staff_quarters', false))
            ->where('folios.'.$timestamp, '>=', $start->setTimezone(config('app.timezone')))
            ->where('folios.'.$timestamp, '<', $end->setTimezone(config('app.timezone')))
            ->join('guests', 'guests.id', '=', 'folios.guest_id')
            ->selectRaw('COUNT(*) as stays, COALESCE(SUM(guests.headcount), 0) as guests')->first();

        return ['stays' => (int) $counts->stays, 'guests' => (int) $counts->guests];
    }
}
