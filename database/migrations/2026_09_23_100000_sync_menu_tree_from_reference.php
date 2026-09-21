<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Canonical snapshot of the full menu tree — parent nesting, sort order,
 * labels, icons — exactly as arranged on the reference (local) environment.
 * A lot of that arrangement was only ever produced by historical migrations
 * running in the order they were authored (not strict filename order) on
 * that one database, so no single prior migration reproduces it end to end
 * on a fresh install run strictly by filename (`php artisan migrate` on a
 * new server left Resend Voucher/Statement/several Kiosk Reports items
 * under "Administration" instead of Transactions/Kiosk).
 *
 * This migration is the new single source of truth for the tree *shape* —
 * idempotent and re-runnable (every row is upserted by slug, same pattern
 * as `2026_08_03_075131_seed_menus_from_data_js`), so it can also just be
 * re-run on an environment that's already drifted instead of requiring
 * `migrate:fresh`.
 *
 * Also grants role_id=1 (super admin) a `can_view` permission row for every
 * navigable (non-title) menu that doesn't already have one, so a menu is
 * never silently hidden from that role just because a permission row was
 * missed when it was created.
 */
return new class extends Migration
{
    // [slug, label, url, icon, parent_slug, sort_order, tab_layout, is_title,
    //  view, add, edit, delete, approve, execute, cancel, reverse, export, print]
    private function items(): array
    {
        return [
            ['main', 'Main', null, 'dashboard', null, 0, 'horizontal', 1, 1,1,1,1,0,0,0,0,0,0],
            ['administration', 'Administration', null, 'shield-lock', null, 1, 'horizontal', 1, 1,1,1,1,0,0,0,0,0,0],
            ['dashboard:merchants', 'Dashboard', '/dashboard/merchants', 'layout-dashboard', 'main', 0, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['merchants', 'Merchants', null, 'building-store', 'main', 1, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['customers', 'Customers', null, 'users-group', 'main', 2, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:terminals', 'Terminals', null, 'device-desktop', 'main', 3, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:transactions', 'Transactions', null, 'transfer', 'main', 4, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:kiosk', 'Kiosk', null, 'device-desktop-analytics', 'main', 5, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:float-management', 'Float Management', null, 'building-bank', 'main', 6, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:giftcards', 'Giftcards', null, 'gift-card', 'main', 7, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:tools', 'Tools', null, 'tool', 'main', 8, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:promotions', 'Promotions', null, 'gift', 'main', 9, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:apps-access-management', 'Access Management', null, 'shield-lock', 'administration', 0, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:settings', 'Settings', null, 'settings', 'administration', 1, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:administration-user-activity', 'User Activity', '/administration/user-activity', 'history', 'administration', 2, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:apps-access-management-menus', 'Menus', '/apps/access-management', 'menu-2', 'pages:apps-access-management', 0, 'horizontal', 0, 1,1,1,0,0,0,0,0,0,0],
            ['pages:apps-access-management-roles', 'Roles', '/apps/access-management/roles', 'shield-check', 'pages:apps-access-management', 1, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:apps-access-management-users', 'Users', '/apps/access-management/users', 'users', 'pages:apps-access-management', 2, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:customers-kyc-upgrade', 'KYC Upgrade', '/customers/kyc-upgrade', 'user-check', 'customers', 0, 'horizontal', 0, 1,0,0,0,1,0,0,0,0,0],
            ['pages:customers-documents', 'Documents', '/customers/documents', 'file-text', 'customers', 1, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:customers-card-verification', 'Card Verification', '/customers/card-verification', 'credit-card', 'customers', 2, 'horizontal', 0, 1,0,0,0,1,0,0,0,0,0],
            ['pages:customers-settlements', 'Settlements', '/customers/settlements', 'building-bank', 'customers', 3, 'horizontal', 0, 1,0,0,0,1,0,0,0,0,0],
            ['pages:customers-bank-loads', 'Bank Loads', '/customers/bank-loads', 'building-bank', 'customers', 4, 'horizontal', 0, 1,0,0,0,1,0,0,0,0,0],
            ['pages:customers-archive', 'Archive', '/customers/archive', 'archive', 'customers', 5, 'horizontal', 0, 1,0,0,1,0,0,0,0,1,0],
            ['pages:customers-logs', 'Customer Logs', '/customers/logs', 'file-text', 'customers', 6, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:customers-failed-logs', 'Customer Failed Linking Logs', '/customers/failed-logs', 'file-x', 'customers', 7, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:merchants-registration', 'Merchant Management', '/merchants/registration', 'building-store', 'merchants', 0, 'vertical', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:merchants-business-management', 'Business Management', '/merchants/business-management', 'building-store', 'merchants', 1, 'horizontal', 0, 1,1,1,1,1,0,0,0,0,0],
            ['pages:merchants-charity-management', 'Charity Management', '/merchants/charity-management', 'heart', 'merchants', 2, 'horizontal', 0, 1,1,1,1,1,0,0,0,0,0],
            ['pages:merchants-settlements', 'Merchant Settlements', '/merchants/settlements', 'building-bank', 'merchants', 3, 'horizontal', 0, 1,0,1,0,1,0,0,0,0,0],
            ['pages:merchants-statement', 'Merchant Statement', '/merchants/statement', 'file-invoice', 'merchants', 4, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:merchants-business-billpay', 'Business Billpay', '/merchants/business-billpay', 'receipt', 'merchants', 5, 'horizontal', 0, 1,0,1,0,1,0,0,0,0,0],
            ['pages:settings-notifications', 'Notifications', '/settings/notifications', 'bell', 'pages:settings', 0, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:settings-customer-app', 'SunCash Customer App', '/settings/customer-app', 'device-mobile', 'pages:settings', 1, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:settings-wu', 'SunCash WU', '/settings/wu', 'send', 'pages:settings', 2, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:promotions-ticket-reports', 'Ticket Reports', '/promotions/ticket-reports', 'ticket', 'pages:promotions', 0, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:promotions-grand-draw', 'Grand Draw', '/promotions/grand-draw', 'trophy', 'pages:promotions', 1, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:promotions-settings', 'Settings', '/promotions/settings', 'settings', 'pages:promotions', 2, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:promotions-signup', 'Sign Up Promotion', '/promotions/signup', 'map-pin', 'pages:promotions', 3, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:float-management-main-reserve-account', 'Main Reserve Account', '/float-management/main-reserve-account', 'building-bank', 'pages:float-management', 0, 'horizontal', 0, 1,1,1,1,1,0,0,0,0,0],
            ['pages:float-management-store-float-replenishments', 'Store Float Replenishments', '/float-management/store-float-replenishments', 'refresh', 'pages:float-management', 1, 'horizontal', 0, 1,1,1,1,1,0,0,0,0,0],
            ['pages:float-management-current-store-float-amounts', 'Current Store Float Amounts', '/float-management/current-store-float-amounts', 'cash', 'pages:float-management', 2, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:float-management-set-main-reserve-account', 'Set Main Reserve Account', '/float-management/set-main-reserve-account', 'settings', 'pages:float-management', 3, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:terminals-management', 'Terminals Management', '/terminals/management', 'device-desktop', 'pages:terminals', 0, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:giftcards-products', 'Products', '/giftcards/products', 'gift-card', 'pages:giftcards', 0, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:transactions-void', 'Void Transaction', '/transactions/void-transaction', 'receipt-refund', 'pages:transactions', 0, 'horizontal', 0, 1,0,0,0,0,0,0,1,0,0],
            ['pages:transactions-resend-receipt', 'Resend Transaction Receipt', '/transactions/resend-receipt', 'receipt', 'pages:transactions', 1, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:transactions-resend-voucher', 'Resend Voucher', '/transactions/resend-voucher', 'ticket', 'pages:transactions', 2, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:kiosk-monitoring-dashboard', 'Monitoring Dashboard', '/kiosk/monitoring-dashboard', 'device-desktop-analytics', 'pages:kiosk', 0, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:kiosk-management', 'Kiosk Management', '/kiosk/management', 'building-store', 'pages:kiosk', 1, 'horizontal', 0, 1,1,1,1,0,1,0,0,0,0],
            ['pages:kiosk-statement', 'Statement', '/kiosk/statement', 'report-money', 'pages:kiosk', 2, 'horizontal', 0, 1,0,0,0,0,0,0,0,1,0],
            ['pages:kiosk-users', 'Users', '/kiosk/users', 'users', 'pages:kiosk', 3, 'horizontal', 0, 1,1,1,1,0,1,0,0,0,0],
            ['pages:kiosk-commission-profiles', 'Commission Profiles', '/kiosk/commission-profiles', 'percentage', 'pages:kiosk', 4, 'horizontal', 0, 1,1,1,1,0,0,0,0,0,0],
            ['pages:kiosk-product-profiles', 'Product Profiles', '/kiosk/product-profiles', 'package', 'pages:kiosk', 5, 'horizontal', 0, 1,1,1,0,0,1,0,0,0,0],
            ['pages:kiosk-commission-approval', 'Commission Approval', '/kiosk/commission-approval', 'clipboard-check', 'pages:kiosk', 6, 'horizontal', 0, 1,0,0,0,1,0,1,0,1,0],
            ['pages:kiosk-deposits-and-adjustments', 'Deposits and Adjustments', '/kiosk/deposits-and-adjustments', 'building-bank', 'pages:kiosk', 7, 'horizontal', 0, 1,0,0,0,0,1,0,0,1,0],
            ['pages:kiosk-cash-management', 'Cash Management', '/kiosk/cash-management', 'cash', 'pages:kiosk', 8, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:kiosk-voucher-pin-tool', 'Voucher Pin Tool', '/kiosk/voucher-pin-tool', 'key', 'pages:kiosk', 9, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:kiosk-confirm-customer-service', 'Confirm Customer Service', '/kiosk/confirm-customer-service', 'clipboard-search', 'pages:kiosk', 10, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:kiosk-reprint-receipt', 'Reprint Receipt', '/kiosk/reprint-receipt', 'receipt', 'pages:kiosk', 11, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,1],
            ['pages:kiosk-reprint-replenishment-receipt', 'Reprint Replenishment Receipt', '/kiosk/reprint-replenishment-receipt', 'receipt-2', 'pages:kiosk', 12, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,1],
            ['pages:kiosk-reports', 'Reports', '/kiosk/reports', 'report', 'pages:kiosk', 13, 'horizontal', 0, 1,0,0,0,0,0,0,0,1,0],
            ['pages:tools-transaction-fees', 'Transaction Fees', '/tools/transaction-fees', 'receipt-2', 'pages:tools', 0, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:tools-transaction-limits', 'Transaction Limits', '/tools/transaction-limits', 'gauge', 'pages:tools', 1, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:tools-forex-rate', 'Forex Rate', '/tools/forex-rate', 'currency-dollar', 'pages:tools', 2, 'horizontal', 0, 1,1,0,0,0,0,0,0,0,0],
            ['pages:tools-send-sms', 'Send SMS', '/tools/send-sms', 'message-2', 'pages:tools', 3, 'horizontal', 0, 1,0,0,0,0,1,0,0,0,0],
            ['pages:tools-customer-management', 'Customer Management', '/tools/customer-management', 'users', 'pages:tools', 4, 'horizontal', 0, 1,0,1,1,0,1,0,0,0,0],
            ['pages:tools-sms-responses', 'SMS Responses', '/tools/sms-responses', 'message-2-cog', 'pages:tools', 5, 'horizontal', 0, 1,0,1,0,0,0,0,0,0,0],
            ['pages:tools-revshare-management', 'Revenue Share Management', '/tools/revshare-management', 'chart-pie', 'pages:tools', 6, 'horizontal', 0, 1,0,0,0,0,0,0,0,0,0],
            ['pages:tools-bank-accounts', 'Bank Accounts', '/tools/bank-accounts', 'building-bank', 'pages:tools', 7, 'horizontal', 0, 1,1,1,0,0,0,0,0,0,0],
        ];
    }

    public function up(): void
    {
        $now = now();

        // Pass 1 — upsert every row keyed on slug (no parent_id yet, since a
        // row's parent might not have been upserted yet within this same pass).
        foreach ($this->items() as $item) {
            [$slug, $label, $url, $icon, , $sortOrder, $tabLayout, $isTitle,
                $view, $add, $edit, $delete, $approve, $execute, $cancel, $reverse, $export, $print] = $item;

            DB::table('menus')->updateOrInsert(
                ['slug' => $slug],
                [
                    'label' => $label,
                    'url' => $url,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                    'tab_layout' => $tabLayout,
                    'is_title' => $isTitle,
                    'is_active' => 1,
                    'is_disabled' => 0,
                    'is_special' => 0,
                    'supports_view' => $view,
                    'supports_add' => $add,
                    'supports_edit' => $edit,
                    'supports_delete' => $delete,
                    'supports_approve' => $approve,
                    'supports_execute' => $execute,
                    'supports_cancel' => $cancel,
                    'supports_reverse' => $reverse,
                    'supports_export' => $export,
                    'supports_print' => $print,
                    'updated_at' => $now,
                ]
            );
        }

        // Pass 2 — resolve each parent_slug -> parent_id and update the row.
        foreach ($this->items() as [$slug, , , , $parentSlug]) {
            $parentId = $parentSlug ? DB::table('menus')->where('slug', $parentSlug)->value('id') : null;

            DB::table('menus')->where('slug', $slug)->update(['parent_id' => $parentId, 'updated_at' => $now]);
        }

        // Grant role_id=1 a can_view row for every navigable menu that's
        // missing one — title rows (Main/Administration) never get one.
        $navigableSlugs = collect($this->items())->reject(fn ($item) => $item[7] === 1)->pluck(0);
        $menus = DB::table('menus')->whereIn('slug', $navigableSlugs)->get(['id', 'slug', 'supports_view', 'supports_add', 'supports_edit', 'supports_delete', 'supports_approve', 'supports_execute', 'supports_cancel', 'supports_reverse', 'supports_export', 'supports_print']);
        $alreadyGranted = DB::table('role_menu_permissions')->where('role_id', 1)->pluck('menu_id')->all();

        foreach ($menus as $menu) {
            if (in_array($menu->id, $alreadyGranted, true)) {
                continue;
            }

            DB::table('role_menu_permissions')->insert([
                'role_id' => 1,
                'menu_id' => $menu->id,
                'can_view' => $menu->supports_view,
                'can_add' => $menu->supports_add,
                'can_edit' => $menu->supports_edit,
                'can_delete' => $menu->supports_delete,
                'can_approve' => $menu->supports_approve,
                'can_execute' => $menu->supports_execute,
                'can_cancel' => $menu->supports_cancel,
                'can_reverse' => $menu->supports_reverse,
                'can_export' => $menu->supports_export,
                'can_print' => $menu->supports_print,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** No down() — this only re-arranges/repairs existing rows, it doesn't own any of them (each is created by its own feature migration). */
    public function down(): void
    {
    }
};
