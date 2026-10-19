<?php

$continuousShiftEnabled = env('SEDONA_CONTINUOUS_SHIFT_ENABLED', true);

return [
    'hotel_name' => 'SEDONA COURT TRAVELLERS INN',
    'midnight_promo_enabled' => env('SEDONA_MIDNIGHT_PROMO_ENABLED', true),
    'seed_password' => env('SEDONA_SEED_PASSWORD'),
    'continuous_shift_enabled' => $continuousShiftEnabled,
    'shift_handover_enabled' => ! $continuousShiftEnabled && env('SEDONA_SHIFT_HANDOVER_ENABLED', false),
    'extra_person_rate' => env('SEDONA_EXTRA_PERSON_RATE', 150),
    'extra_bedding_rate' => env('SEDONA_EXTRA_BEDDING_RATE', 100),
    'extra_towel_rate' => env('SEDONA_EXTRA_TOWEL_RATE', 100),
    'extra_hour_rate' => env('SEDONA_EXTRA_HOUR_RATE', 130),
    'overstay_grace_minutes' => env('SEDONA_OVERSTAY_GRACE_MINUTES', 15),
    'senior_pwd_discount_percent' => env('SEDONA_DISCOUNT_PERCENT', 20),
    'currency_symbol' => '₱',
];
