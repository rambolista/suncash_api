<?php

return [
    /*
     * The currently running promo's `suncash_promo_settings.code` — mirrors
     * legacy's SUNCASH_ACTIVE_PROMO constant. Ticket Reports and Settings
     * both scope to this single active promo, matching legacy behavior.
     */
    'active_code' => env('PROMOTIONS_ACTIVE_CODE', 'summer_cool_down_reloaded_promo'),

    /*
     * Grand Draw — mirrors legacy's settings::$grand_draw_promo_islands
     * (which island(s) of customers.island are eligible) and
     * PROMO_TICKET_LIMIT (minimum tickets a customer needs to be
     * draw-eligible).
     */
    'grand_draw_islands' => array_filter(array_map(
        'intval',
        explode(',', (string) env('PROMOTIONS_GRAND_DRAW_ISLANDS', '3'))
    )),
    'grand_draw_ticket_limit' => (int) env('PROMOTIONS_GRAND_DRAW_TICKET_LIMIT', 1),
];
