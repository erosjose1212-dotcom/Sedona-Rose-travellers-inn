<?php

namespace App\Services;

use App\Models\Folio;
use Carbon\CarbonInterface;

class BillingService
{
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public function calculate(Folio $folio, ?CarbonInterface $at = null, bool $includeOvertime = true): array
    {
        $folio->loadMissing('room');
        $settled = ! $folio->isActive();
        $checkOutTime = $settled ? ($folio->checked_out_at ?? now()) : ($at ?? now());
        $tierHours = $folio->room?->getTierHours($folio->rate_tier) ?: 3;
        $xtendHours = (int) $folio->extra_hours;
        if ($folio->rate_tier === 'promo' && $folio->room && ! $folio->room->is_staff_quarters) {
            $promoCheckout = $folio->room->getCheckoutForTier('promo', $folio->checked_in_at);
            $tierHours = ($promoCheckout->getTimestamp() - $folio->checked_in_at->getTimestamp()) / 3600;
        }
        $totalBookedHours = $tierHours + $xtendHours;
        $elapsedSeconds = max(0, $folio->checked_in_at->diffInSeconds($checkOutTime, false));
        $actualHoursDiff = max(1, (int) ceil($elapsedSeconds / 3600));
        $graceSeconds = max(0, (int) config('sedona.overstay_grace_minutes', 15)) * 60;
        $billableOverstaySeconds = max(0, $elapsedSeconds - round($totalBookedHours * 3600) - $graceSeconds);
        $excessHours = $settled ? (int) $folio->overtime_hours : ($includeOvertime ? (int) ceil($billableOverstaySeconds / 3600) : 0);
        $roomCents = self::cents($folio->room_charge);
        $posCents = 0;
        $extraCents = 0;
        $addOnItems = [];
        foreach ($folio->pos_items ?? [] as $item) {
            $amount = self::cents($item['subtotal'] ?? 0);
            $posCents += $amount;
            $addOnItems[] = ['name' => $item['name'] ?? 'Item', 'qty' => $item['qty'] ?? 1, 'price' => (float) ($item['price'] ?? 0), 'amount' => $amount / 100];
        }
        $posItems = $addOnItems;
        foreach (['extra_persons' => ['Extra Person', 'extra_person_rate', 150], 'extra_bedding' => ['Extra Bedding', 'extra_bedding_rate', 100], 'extra_towels' => ['Extra Towel', 'extra_towel_rate', 100]] as $field => [$name, $key, $fallback]) {
            $qty = (int) $folio->{$field};
            $price = self::cents(config('sedona.'.$key, $fallback));
            $extraCents += $qty * $price;
            if ($qty > 0) {
                $addOnItems[] = ['name' => $name, 'qty' => $qty, 'price' => $price / 100, 'amount' => $qty * $price / 100];
            }
        }
        $hoursCents = ($xtendHours + $excessHours) * 13000;
        if ($settled) {
            $savedExtraCents = max(0, self::cents($folio->surcharge_total) - $hoursCents);
            if ($savedExtraCents !== $extraCents) {
                $extraCents = $savedExtraCents;
                $addOnItems = $posItems;
                if ($extraCents > 0) {
                    $addOnItems[] = ['name' => 'Saved stay extras', 'qty' => 1, 'price' => $extraCents / 100, 'amount' => $extraCents / 100];
                }
            }
        }
        $surchargeCents = $extraCents + $hoursCents;
        $grossCents = $roomCents + $surchargeCents + $posCents;
        $discountType = DiscountService::normalizeDiscountType($folio->discount_type);
        if (! $discountType && $folio->senior_pwd_discount) {
            $discountType = 'SENIOR';
        }
        $discountCents = $discountType ? min($roomCents, self::cents($folio->discount_override_amount
            ?? DiscountService::getDiscountAmount($discountType, $folio->room?->type ?? 'Classic Room', $folio->rate_tier, $folio->discount_schedule))) : 0;
        if ($settled) {
            $grossCents = self::cents($folio->gross_total);
            $surchargeCents = self::cents($folio->surcharge_total);
            $discountCents = self::cents($folio->discount_amount);
        }
        $afterDiscount = max(0, $grossCents - $discountCents);
        $depositCents = self::cents($folio->security_deposit);
        $applyDeposit = $folio->deposit_status !== 'not_applied';
        $depositApplied = $applyDeposit ? min($depositCents, $afterDiscount) : 0;
        $remainingDeposit = max(0, $depositCents - $depositApplied - self::cents($folio->deposit_refunded_amount));
        $netCents = $settled ? self::cents($folio->net_total) : max(0, $afterDiscount - $depositApplied);

        return [
            'checkInTime' => $folio->checked_in_at, 'checkOutTime' => $checkOutTime, 'expectedCheckoutAt' => $folio->expected_checkout_at,
            'tierHours' => $tierHours, 'xtendHours' => $xtendHours, 'totalBookedHours' => $totalBookedHours, 'actualHoursDiff' => $actualHoursDiff, 'excessHours' => $excessHours,
            'xtendRate' => $xtendHours * 130, 'excessRate' => $excessHours * 130, 'addHoursRate' => $hoursCents / 100,
            'roomRate' => $roomCents / 100, 'addOnItems' => $addOnItems, 'addOnsTotal' => ($posCents + $extraCents) / 100,
            'subTotal' => $grossCents / 100, 'totalCharge' => $grossCents / 100, 'grossTotal' => $grossCents / 100,
            'totalDiscount' => $discountCents / 100, 'discountType' => $discountType ?? 'NONE', 'discountIdRef' => $folio->discount_id_ref,
            'amountToPay' => $afterDiscount / 100, 'amountAfterDiscount' => $afterDiscount / 100,
            'deposit' => $depositCents / 100, 'applyDeposit' => $applyDeposit, 'depositApplied' => $depositApplied / 100,
            'remainingDeposit' => $remainingDeposit / 100, 'totalAmountToPay' => $netCents / 100, 'finalBalanceToPay' => $netCents / 100,
            'attributes' => ['surcharge_total' => $surchargeCents / 100, 'pos_total' => $posCents / 100, 'gross_total' => $grossCents / 100, 'discount_amount' => $discountCents / 100, 'net_total' => $netCents / 100, 'overtime_hours' => $excessHours],
        ];
    }
}
