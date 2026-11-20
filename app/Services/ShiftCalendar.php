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
        $configuration = DB::table('accounting_settings')->where('id', 1)->value('configuration');
        $settings = json_decode($configuration ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $day = CarbonImmutable::parse($date, 'Asia/Manila');

        return match (true) {
            isset($settings['shift_601_cutover_date']) && $date >= $settings['shift_601_cutover_date'] => $day->setTime(6, 1),
            isset($settings['shift_cutover_date']) && $date >= $settings['shift_cutover_date'] => $day->setTime(5, 0),
            default => $day->setTime(6, 30),
        };
    }

    public static function scheduleCutover(string $date, string $time = '05:00'): void
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Enter a valid cutover date in YYYY-MM-DD format.');
        }

        $scheduleKey = match ($time) {
            '05:00' => 'shift_cutover_date',
            '06:01' => 'shift_601_cutover_date',
            default => throw new InvalidArgumentException('Choose 05:00 or 06:01 for the Manila shift start.'),
        };

        DB::transaction(function () use ($date, $time, $scheduleKey): void {
            $row = DB::table('accounting_settings')->where('id', 1)->lockForUpdate()->first();
            $settings = json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR);
            if (($settings[$scheduleKey] ?? null) === $date) {
                return;
            }
            if (isset($settings[$scheduleKey])) {
                throw new InvalidArgumentException('The saved cutover date cannot be changed.');
            }
            if ($date <= now('Asia/Manila')->toDateString()) {
                throw new InvalidArgumentException('Choose a future Manila date so posted activity keeps its business date.');
            }

            if ($time === '05:00' && isset($settings['shift_601_cutover_date'])) {
                throw new InvalidArgumentException('The 6:01 AM schedule is already saved.');
            }
            if ($time === '06:01' && isset($settings['shift_cutover_date']) && $date <= $settings['shift_cutover_date']) {
                throw new InvalidArgumentException('The 6:01 AM cutover must follow the 5:00 AM cutover date.');
            }

            $start = CarbonImmutable::parse($date.' '.$time, 'Asia/Manila');
            $postedAt = $start->min(self::start($date))->setTimezone(config('app.timezone'));
            if (DB::table('business_days')->where('business_date', '>=', $start->subDay()->toDateString())->where('version', '>', 0)->exists()
                || DB::table('payment_entries')->where('posted_at', '>=', $postedAt)->exists()
                || DB::table('cash_movements')->where('posted_at', '>=', $postedAt)->exists()
                || DB::table('statement_lines')->where('posted_at', '>=', $postedAt)->exists()) {
                throw new InvalidArgumentException('Existing postings or a day-close snapshot overlap the proposed cutover.');
            }

            $settings[$scheduleKey] = $date;
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
