<?php

namespace App\Support;

final class MenuPermissions
{
    public const ACTIONS = [
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_approve',
        'can_execute',
        'can_cancel',
        'can_reverse',
        'can_export',
        'can_print',
    ];

    public const CAPABILITIES = [
        'can_view' => 'supports_view',
        'can_add' => 'supports_add',
        'can_edit' => 'supports_edit',
        'can_delete' => 'supports_delete',
        'can_approve' => 'supports_approve',
        'can_execute' => 'supports_execute',
        'can_cancel' => 'supports_cancel',
        'can_reverse' => 'supports_reverse',
        'can_export' => 'supports_export',
        'can_print' => 'supports_print',
    ];

    private function __construct()
    {
    }
}
