<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShiftCalendar
{
    public static function start(string $date): CarbonImmutable
    {
        $cutover = DB::table('accounting_settings')->where('id', 1)->value('configuration->shift_cutover_date');
        $newSchedule = $cutover !== null && $date >= $cutover;

        return CarbonImmutable::parse($date, 'Asia/Manila')->setTime($newSchedule ? 5 : 6, $newSchedule ? 0 : 30);
    }

    public static function scheduleCutover(string $date): void
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Enter a valid cutover date in YYYY-MM-DD format.');
        }

        DB::transaction(function () use ($date): void {
            $row = DB::table('accounting_settings')->where('id', 1)->lockForUpdate()->first();
            $settings = json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR);
            if (($settings['shift_cutover_date'] ?? null) === $date) {
                return;
            }
            if (isset($settings['shift_cutover_date'])) {
                throw new InvalidArgumentException('The saved cutover date cannot be changed.');
            }
            if ($date <= now('Asia/Manila')->toDateString()) {
                throw new InvalidArgumentException('Choose a future Manila date so posted activity keeps its business date.');
            }

            $start = CarbonImmutable::parse($date, 'Asia/Manila')->setTime(5, 0);
            $postedAt = $start->setTimezone(config('app.timezone'));
            if (DB::table('business_days')->where('business_date', '>=', $start->subDay()->toDateString())->where('version', '>', 0)->exists()
                || DB::table('payment_entries')->where('posted_at', '>=', $postedAt)->exists()
                || DB::table('cash_movements')->where('posted_at', '>=', $postedAt)->exists()
                || DB::table('statement_lines')->where('posted_at', '>=', $postedAt)->exists()) {
                throw new InvalidArgumentException('Existing postings or a day-close snapshot overlap the proposed cutover.');
            }

            $settings['shift_cutover_date'] = $date;
            DB::table('accounting_settings')->where('id', 1)->update([
                'configuration' => json_encode($settings, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public static function date(?CarbonInterface $at = null): string
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone('Asia/Manila');

        return ($local->lt(self::start($local->toDateString())) ? $local->subDay() : $local)->toDateString();
    }

    public static function shift(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return $at->lt(self::start(self::date($at))->addHours(12)) ? 'day' : 'night';
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public static function bounds(string $date, string $shift = 'all'): array
    {
        $start = self::start($date);
        $next = self::start($start->addDay()->toDateString());
        $evening = $start->addHours(12);

        return match ($shift) {
            'day' => [$start, $evening],
            'night' => [$evening, $next],
            default => [$start, $next],
        };
    }

    public static function label(string $date, string $shift = 'all'): string
    {
        [$start, $end] = self::bounds($date, $shift);

        return $start->format('g:i A').'–'.($start->isSameDay($end) ? '' : 'next ').$end->format('g:i A');
    }
}
