<?php

return [
    /*
     * The currently running promo's `suncash_promo_settings.code` — mirrors
     * legacy's SUNCASH_ACTIVE_PROMO constant. Ticket Reports and Settings
     * both scope to this single active promo, matching legacy behavior.
     */
    'active_code' => env('PROMOTIONS_ACTIVE_CODE', 'summer_cool_down_reloaded_promo'),
];
