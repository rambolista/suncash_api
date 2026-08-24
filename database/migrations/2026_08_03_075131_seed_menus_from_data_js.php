<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: seed all menu items from the front-end data.js into the
 * `menus` table.
 *
 * Strategy (idempotent):
 *   Pass 1 — upsert every row keyed on `slug` (no parent_id yet).
 *   Pass 2 — resolve each `parent_slug` → `parent_id` and update the row.
 *
 * Running this migration a second time is safe; existing rows are updated in
 * place so manual edits to label/icon/badge are overwritten — roll back first
 * if you want a clean slate.
 *
 * Down: deletes only the rows whose slugs are in this file, preserving any
 * custom menus the user may have added.
 */
return new class extends Migration
{
    // ── All menu items from data.js ──────────────────────────────────────────
    // Each entry: [slug, label, url|null, icon|null, parent_slug|null,
    //              sort_order, is_title, is_active, badge_text|null, badge_class|null]
    private function items(): array
    {
        return [
            // ── MAIN ─────────────────────────────────────────────────────────
            ['main',                                   'Main',                  null,                               'dashboard',          null,             0,   true,  true,  null,  null],
            ['dashboards',                             'Dashboards',            null,                               'dashboard',          'main',           0,   false, true,  null,  null],
            ['pages:dashboard-ecommerce',              'Ecommerce',             '/dashboard/ecommerce',             null,                 'dashboards',     0,   false, true,  null,  null],
            ['pages:dashboard-analytics',              'Analytics',             '/dashboard/analytics',             null,                 'dashboards',     1,   false, true,  null,  null],
            ['pages:dashboard-projects',               'Projects',              '/dashboard/projects',              null,                 'dashboards',     2,   false, true,  null,  null],
            ['pages:landing',                          'Landing',               '/landing',                         'rocket',             'main',           1,   false, true,  null,  null],

            // ── APPS ─────────────────────────────────────────────────────────
            ['apps',                                   'Apps',                  null,                               'apps',               null,             1,   true,  true,  null,  null],

            // Ecommerce
            ['ecommerce',                              'Ecommerce',             null,                               'basket',             'apps',           0,   false, true,  null,  null],
            ['pages:apps-ecommerce-marketplace',       'Marketplace',           '/apps/ecommerce/marketplace',      'building-store',     'ecommerce',      0,   false, true,  null,  null],
            ['products',                               'Products',              null,                               'shirt-sport',        'ecommerce',      1,   false, true,  null,  null],
            ['pages:apps-ecommerce-products',          'Products',              '/apps/ecommerce/products',         null,                 'products',       0,   false, true,  null,  null],
            ['pages:apps-ecommerce-products-grid',     'Products Grid',         '/apps/ecommerce/products-grid',    null,                 'products',       1,   false, true,  null,  null],
            ['pages:apps-ecommerce-product-details',   'Product Details',       '/apps/ecommerce/product-details',  null,                 'products',       2,   false, true,  null,  null],
            ['pages:apps-ecommerce-product-add',       'Add Product',           '/apps/ecommerce/product-add',      null,                 'products',       3,   false, true,  null,  null],
            ['pages:apps-ecommerce-categories',        'Categories',            '/apps/ecommerce/categories',       'category',           'ecommerce',      2,   false, true,  null,  null],
            ['orders',                                 'Orders',                null,                               'shopping-bag',       'ecommerce',      3,   false, true,  null,  null],
            ['pages:apps-ecommerce-orders',            'Orders',                '/apps/ecommerce/orders',           null,                 'orders',         0,   false, true,  null,  null],
            ['pages:apps-ecommerce-order-details',     'Order Details',         '/apps/ecommerce/order-details',    null,                 'orders',         1,   false, true,  null,  null],
            ['pages:apps-ecommerce-order-add',         'Add/Edit Order',        '/apps/ecommerce/order-add',        null,                 'orders',         2,   false, true,  null,  null],
            ['pages:apps-ecommerce-customers',         'Customers',             '/apps/ecommerce/customers',        'users',              'ecommerce',      4,   false, true,  null,  null],
            ['pages:apps-ecommerce-cart',              'Cart',                  '/apps/ecommerce/cart',             'shopping-cart',      'ecommerce',      5,   false, true,  null,  null],
            ['pages:apps-ecommerce-checkout',          'Checkout',              '/apps/ecommerce/checkout',         'shopping-bag-heart', 'ecommerce',      6,   false, true,  null,  null],
            ['sellers',                                'Sellers',               null,                               'users-group',        'ecommerce',      7,   false, true,  null,  null],
            ['pages:apps-ecommerce-sellers',           'Sellers',               '/apps/ecommerce/sellers',          null,                 'sellers',        0,   false, true,  null,  null],
            ['pages:apps-ecommerce-seller-details',    'Sellers Details',       '/apps/ecommerce/seller-details',   null,                 'sellers',        1,   false, true,  null,  null],
            ['pages:apps-ecommerce-refunds',           'Refunds',               '/apps/ecommerce/refunds',          'credit-card-refund', 'ecommerce',      8,   false, true,  null,  null],
            ['pages:apps-ecommerce-reviews',           'Reviews',               '/apps/ecommerce/reviews',          'message-star',       'ecommerce',      9,   false, true,  null,  null],
            ['inventory',                              'Inventory',             null,                               'building-warehouse', 'ecommerce',      10,  false, true,  null,  null],
            ['pages:apps-ecommerce-warehouse',         'Warehouse',             '/apps/ecommerce/warehouse',        null,                 'inventory',      0,   false, true,  null,  null],
            ['pages:apps-ecommerce-product-stocks',    'Product Stocks',        '/apps/ecommerce/product-stocks',   null,                 'inventory',      1,   false, true,  null,  null],
            ['pages:apps-ecommerce-purchased-orders',  'Purchased Orders',      '/apps/ecommerce/purchased-orders', null,                 'inventory',      2,   false, true,  null,  null],
            ['reports',                                'Reports',               null,                               'report',             'ecommerce',      11,  false, true,  null,  null],
            ['pages:apps-ecommerce-product-views',     'Product Views',         '/apps/ecommerce/product-views',    null,                 'reports',        0,   false, true,  null,  null],
            ['pages:apps-ecommerce-sales',             'Sales',                 '/apps/ecommerce/sales',            null,                 'reports',        1,   false, true,  null,  null],
            ['pages:apps-ecommerce-attributes',        'Attributes',            '/apps/ecommerce/attributes',       'wand',               'ecommerce',      12,  false, true,  null,  null],
            ['pages:apps-ecommerce-settings',          'Settings',              '/apps/ecommerce/settings',         'settings',           'ecommerce',      13,  false, true,  null,  null],

            // Access Management
            ['pages:apps-access-management',           'Access Management',     '/apps/access-management',          'shield-lock',        'apps',           1,   false, true,  null,  null],

            // Email
            ['email',                                  'Email',                 null,                               'mailbox',            'apps',           2,   false, true,  'New', 'bg-danger text-white'],
            ['pages:apps-email-inbox',                 'Inbox',                 '/apps/email/inbox',                null,                 'email',          0,   false, true,  null,  null],
            ['pages:apps-email-details',               'Details',               '/apps/email/details',              null,                 'email',          1,   false, true,  null,  null],
            ['pages:apps-email-compose',               'Compose',               '/apps/email/compose',              null,                 'email',          2,   false, true,  null,  null],

            // Users
            ['users',                                  'Users',                 null,                               'users',              'apps',           3,   false, true,  null,  null],
            ['pages:apps-users-contacts',              'Contacts',              '/apps/users/contacts',             null,                 'users',          0,   false, true,  null,  null],
            ['pages:apps-users-roles',                 'Roles',                 '/apps/users/roles',                null,                 'users',          1,   false, true,  null,  null],
            ['pages:apps-users-role-details',          'Role Details',          '/apps/users/role-details',         null,                 'users',          2,   false, true,  null,  null],
            ['pages:apps-users-permissions',           'Permissions',           '/apps/users/permissions',          null,                 'users',          3,   false, true,  null,  null],

            // Projects
            ['projects',                               'Projects',              null,                               'briefcase',          'apps',           4,   false, true,  null,  null],
            ['pages:apps-projects-grid',               'My Projects',           '/apps/projects/grid',              null,                 'projects',       0,   false, true,  null,  null],
            ['pages:apps-projects-list',               'Projects List',         '/apps/projects/list',              null,                 'projects',       1,   false, true,  null,  null],
            ['pages:apps-projects-details',            'View Project',          '/apps/projects/details',           null,                 'projects',       2,   false, true,  null,  null],
            ['pages:apps-projects-kanban',             'Kanban Board',          '/apps/projects/kanban',            null,                 'projects',       3,   false, true,  null,  null],
            ['pages:apps-projects-team-board',         'Team Board',            '/apps/projects/team-board',        null,                 'projects',       4,   false, true,  null,  null],
            ['pages:apps-projects-activity',           'Activity Steam',        '/apps/projects/activity',          null,                 'projects',       5,   false, true,  null,  null],

            // Standalone apps
            ['pages:apps-file-manager',                'File Manager',          '/apps/file-manager',               'folder-open',        'apps',           5,   false, true,  null,  null],
            ['pages:apps-chat',                        'Chat',                  '/apps/chat',                       'message',            'apps',           6,   false, true,  null,  null],
            ['pages:apps-calendar',                    'Calendar',              '/apps/calendar',                   'calendar',           'apps',           7,   false, true,  null,  null],
            ['pages:apps-social-feed',                 'Social Feed',           '/apps/social-feed',                'rss',                'apps',           8,   false, true,  null,  null],

            // Invoice
            ['invoice',                                'Invoice',               null,                               'invoice',            'apps',           9,   false, true,  null,  null],
            ['pages:apps-invoice-list',                'Invoices',              '/apps/invoice/list',               null,                 'invoice',        0,   false, true,  null,  null],
            ['pages:apps-invoice-details',             'Single Invoice',        '/apps/invoice/details',            null,                 'invoice',        1,   false, true,  null,  null],
            ['pages:apps-invoice-create',              'New Invoice',           '/apps/invoice/create',             null,                 'invoice',        2,   false, true,  null,  null],

            ['pages:apps-companies',                   'Companies',             '/apps/companies',                  'building',           'apps',           10,  false, true,  null,  null],

            // More Apps
            ['more-apps',                              'More Apps',             null,                               'apps',               'apps',           11,  false, true,  null,  null],
            ['pages:apps-clients',                     'Clients',               '/apps/clients',                    'users-group',        'more-apps',      0,   false, true,  null,  null],
            ['pages:apps-outlook',                     'Outlook View',          '/apps/outlook',                    'layout-cards',       'more-apps',      1,   false, true,  null,  null],
            ['pages:apps-vote-list',                   'Vote List',             '/apps/vote-list',                  'caret-up-down',      'more-apps',      2,   false, true,  null,  null],
            ['pages:apps-issue-tracker',               'Issue Tracker',         '/apps/issue-tracker',              'bug',                'more-apps',      3,   false, true,  null,  null],
            ['pages:apps-api-keys',                    'API Keys',              '/apps/api-keys',                   'key',                'more-apps',      4,   false, true,  null,  null],
            ['pages:apps-manage',                      'Manage Apps',           '/apps/manage',                     'apps',               'more-apps',      5,   false, true,  null,  null],
            ['blog',                                   'Blog',                  null,                               'article',            'more-apps',      6,   false, true,  null,  null],
            ['pages:apps-blog-list',                   'Blog List',             '/apps/blog/list',                  null,                 'blog',           0,   false, true,  null,  null],
            ['pages:apps-blog-grid',                   'Blog Grid',             '/apps/blog/grid',                  null,                 'blog',           1,   false, true,  null,  null],
            ['pages:apps-blog-article',                'Article',               '/apps/blog/article',               null,                 'blog',           2,   false, true,  null,  null],
            ['pages:apps-blog-add',                    'Add Article',           '/apps/blog/add',                   null,                 'blog',           3,   false, true,  null,  null],
            ['pages:apps-pin-board',                   'Pin Board',             '/apps/pin-board',                  'pin',                'more-apps',      7,   false, true,  null,  null],
            ['forum',                                  'Forum',                 null,                               'messages',           'more-apps',      8,   false, true,  null,  null],
            ['pages:apps-forum-view',                  'Forum View',            '/apps/forum/view',                 null,                 'forum',          0,   false, true,  null,  null],
            ['pages:apps-forum-post',                  'Forum Post',            '/apps/forum/post',                 null,                 'forum',          1,   false, true,  null,  null],

            // ── CUSTOM PAGES ──────────────────────────────────────────────────
            ['custom-pages',                           'Custom Pages',          null,                               'files',              null,             2,   true,  true,  null,  null],

            // Pages
            ['pages',                                  'Pages',                 null,                               'files',              'custom-pages',   0,   false, true,  null,  null],
            ['pages:pages-profile',                    'Profile',               '/pages/profile',                   null,                 'pages',          0,   false, true,  null,  null],
            ['pages:pages-account-settings',           'Account Settings',      '/pages/account-settings',          null,                 'pages',          1,   false, true,  null,  null],
            ['pages:pages-faq',                        'FAQ',                   '/pages/faq',                       null,                 'pages',          2,   false, true,  null,  null],
            ['pages:pages-pricing',                    'Pricing',               '/pages/pricing',                   null,                 'pages',          3,   false, true,  null,  null],
            ['pages:pages-empty',                      'Empty Page',            '/pages/empty',                     null,                 'pages',          4,   false, true,  null,  null],
            ['pages:pages-timeline',                   'Timeline',              '/pages/timeline',                  null,                 'pages',          5,   false, true,  null,  null],
            ['pages:pages-gallery',                    'Gallery',               '/pages/gallery',                   null,                 'pages',          6,   false, true,  null,  null],
            ['pages:pages-sitemap',                    'Sitemap',               '/pages/sitemap',                   null,                 'pages',          7,   false, true,  null,  null],
            ['pages:pages-search-results',             'Search Results',        '/pages/search-results',            null,                 'pages',          8,   false, true,  null,  null],
            ['pages:pages-coming-soon',                'Coming Soon',           '/pages/coming-soon',               null,                 'pages',          9,   false, true,  null,  null],
            ['pages:pages-privacy-policy',             'Privacy Policy',        '/pages/privacy-policy',            null,                 'pages',          10,  false, true,  null,  null],
            ['pages:pages-terms-conditions',           'Terms & Conditions',    '/pages/terms-conditions',          null,                 'pages',          11,  false, true,  null,  null],

            // Plugins
            ['plugins',                                'Plugins',               null,                               'cpu',                'custom-pages',   1,   false, true,  null,  null],
            ['pages:plugins-sortable',                 'Sortable List',         '/plugins/sortable',                null,                 'plugins',        0,   false, true,  null,  null],
            ['pages:plugins-text-diff',                'Text Diff',             '/plugins/text-diff',               null,                 'plugins',        1,   false, true,  null,  null],
            ['pages:plugins-pdf-viewer',               'PDF Viewer',            '/plugins/pdf-viewer',              null,                 'plugins',        2,   false, true,  null,  null],
            ['pages:plugins-sweet-alerts',             'Sweet Alerts',          '/plugins/sweet-alerts',            null,                 'plugins',        3,   false, true,  null,  null],
            ['pages:plugins-idle-timer',               'Idle Timer',            '/plugins/idle-timer',              null,                 'plugins',        4,   false, true,  null,  null],
            ['pages:plugins-pass-meter',               'Password Meter',        '/plugins/pass-meter',              null,                 'plugins',        5,   false, true,  null,  null],
            ['pages:plugins-clipboard',                'Clipboard',             '/plugins/clipboard',               null,                 'plugins',        6,   false, true,  null,  null],
            ['pages:plugins-tree-view',                'Tree View',             '/plugins/tree-view',               null,                 'plugins',        7,   false, true,  null,  null],
            ['pages:plugins-loading-buttons',          'Loading Buttons',       '/plugins/loading-buttons',         null,                 'plugins',        8,   false, true,  null,  null],
            ['pages:plugins-masonry',                  'Masonry',               '/plugins/masonry',                 null,                 'plugins',        9,   false, true,  null,  null],
            ['pages:plugins-tour',                     'Tour',                  '/plugins/tour',                    null,                 'plugins',        10,  false, true,  null,  null],
            ['pages:plugins-animation',                'Animation',             '/plugins/animation',               null,                 'plugins',        11,  false, true,  null,  null],
            ['pages:plugins-video-player',             'Video Player',          '/plugins/video-player',            null,                 'plugins',        12,  false, true,  null,  null],

            // Authentication
            ['authentication',                         'Authentication',        null,                               'password-user',      'custom-pages',   2,   false, true,  null,  null],
            ['auth-basic',                             'Basic',                 null,                               null,                 'authentication', 0,   false, true,  null,  null],
            ['pages:auth-sign-in',                     'Sign In',               '/auth/sign-in',                    null,                 'auth-basic',     0,   false, true,  null,  null],
            ['pages:auth-sign-up',                     'Sign Up',               '/auth/sign-up',                    null,                 'auth-basic',     1,   false, true,  null,  null],
            ['pages:auth-reset-pass',                  'Reset Password',        '/auth/reset-pass',                 null,                 'auth-basic',     2,   false, true,  null,  null],
            ['pages:auth-new-pass',                    'New Password',          '/auth/new-pass',                   null,                 'auth-basic',     3,   false, true,  null,  null],
            ['pages:auth-two-factor',                  'Two Factor',            '/auth/two-factor',                 null,                 'auth-basic',     4,   false, true,  null,  null],
            ['pages:auth-lock-screen',                 'Lock Screen',           '/auth/lock-screen',                null,                 'auth-basic',     5,   false, true,  null,  null],
            ['pages:auth-success-mail',                'Success Mail',          '/auth/success-mail',               null,                 'auth-basic',     6,   false, true,  null,  null],
            ['pages:auth-login-pin',                   'Login with PIN',        '/auth/login-pin',                  null,                 'auth-basic',     7,   false, true,  null,  null],
            ['pages:auth-delete-account',              'Delete Account',        '/auth/delete-account',             null,                 'auth-basic',     8,   false, true,  null,  null],
            ['auth-card',                              'Card',                  null,                               null,                 'authentication', 1,   false, true,  null,  null],
            ['pages:auth-card-sign-in',                'Sign In',               '/auth/card/sign-in',               null,                 'auth-card',      0,   false, true,  null,  null],
            ['pages:auth-card-sign-up',                'Sign Up',               '/auth/card/sign-up',               null,                 'auth-card',      1,   false, true,  null,  null],
            ['pages:auth-card-reset-pass',             'Reset Password',        '/auth/card/reset-pass',            null,                 'auth-card',      2,   false, true,  null,  null],
            ['pages:auth-card-new-pass',               'New Password',          '/auth/card/new-pass',              null,                 'auth-card',      3,   false, true,  null,  null],
            ['pages:auth-card-two-factor',             'Two Factor',            '/auth/card/two-factor',            null,                 'auth-card',      4,   false, true,  null,  null],
            ['pages:auth-card-lock-screen',            'Lock Screen',           '/auth/card/lock-screen',           null,                 'auth-card',      5,   false, true,  null,  null],
            ['pages:auth-card-success-mail',           'Success Mail',          '/auth/card/success-mail',          null,                 'auth-card',      6,   false, true,  null,  null],
            ['pages:auth-card-login-pin',              'Login with PIN',        '/auth/card/login-pin',             null,                 'auth-card',      7,   false, true,  null,  null],
            ['pages:auth-card-delete-account',         'Delete Account',        '/auth/card/delete-account',        null,                 'auth-card',      8,   false, true,  null,  null],
            ['auth-split',                             'Split',                 null,                               null,                 'authentication', 2,   false, true,  null,  null],
            ['pages:auth-split-sign-in',               'Sign In',               '/auth/split/sign-in',              null,                 'auth-split',     0,   false, true,  null,  null],
            ['pages:auth-split-sign-up',               'Sign Up',               '/auth/split/sign-up',              null,                 'auth-split',     1,   false, true,  null,  null],
            ['pages:auth-split-reset-pass',            'Reset Password',        '/auth/split/reset-pass',           null,                 'auth-split',     2,   false, true,  null,  null],
            ['pages:auth-split-new-pass',              'New Password',          '/auth/split/new-pass',             null,                 'auth-split',     3,   false, true,  null,  null],
            ['pages:auth-split-two-factor',            'Two Factor',            '/auth/split/two-factor',           null,                 'auth-split',     4,   false, true,  null,  null],
            ['pages:auth-split-lock-screen',           'Lock Screen',           '/auth/split/lock-screen',          null,                 'auth-split',     5,   false, true,  null,  null],
            ['pages:auth-split-success-mail',          'Success Mail',          '/auth/split/success-mail',         null,                 'auth-split',     6,   false, true,  null,  null],
            ['pages:auth-split-login-pin',             'Login with PIN',        '/auth/split/login-pin',            null,                 'auth-split',     7,   false, true,  null,  null],
            ['pages:auth-split-delete-account',        'Delete Account',        '/auth/split/delete-account',       null,                 'auth-split',     8,   false, true,  null,  null],

            // Error Pages
            ['error-pages',                            'Error Pages',           null,                               'alert-triangle',     'custom-pages',   3,   false, true,  null,  null],
            ['pages:error-400',                        '400 Bad Request',       '/error/400',                       null,                 'error-pages',    0,   false, true,  null,  null],
            ['pages:error-401',                        '401 Unauthorized',      '/error/401',                       null,                 'error-pages',    1,   false, true,  null,  null],
            ['pages:error-403',                        '403 Forbidden',         '/error/403',                       null,                 'error-pages',    2,   false, true,  null,  null],
            ['pages:error-404',                        '404 Not Found',         '/error/404',                       null,                 'error-pages',    3,   false, true,  null,  null],
            ['pages:error-408',                        '408 Request Timeout',   '/error/408',                       null,                 'error-pages',    4,   false, true,  null,  null],
            ['pages:error-500',                        '500 Internal Server',   '/error/500',                       null,                 'error-pages',    5,   false, true,  null,  null],
            ['pages:error-maintenance',                'Maintenance',           '/error/maintenance',               null,                 'error-pages',    6,   false, true,  null,  null],

            // ── LAYOUTS ───────────────────────────────────────────────────────
            ['layouts',                                'Layouts',               null,                               'table-column',       null,             3,   true,  true,  null,  null],
            ['layout-options',                         'Layout Options',        null,                               'layout',             'layouts',        0,   false, true,  null,  null],
            ['pages:layouts-scrollable',               'Scrollable',            '/layouts/scrollable',              null,                 'layout-options', 0,   false, true,  null,  null],
            ['pages:layouts-compact',                  'Compact',               '/layouts/compact',                 null,                 'layout-options', 1,   false, true,  null,  null],
            ['pages:layouts-boxed',                    'Boxed',                 '/layouts/boxed',                   null,                 'layout-options', 2,   false, true,  null,  null],
            ['pages:layouts-horizontal',               'Horizontal',            '/layouts/horizontal',              null,                 'layout-options', 3,   false, true,  null,  null],
            ['pages:layouts-preloader',                'Preloader',             '/layouts/preloader',               null,                 'layout-options', 4,   false, true,  null,  null],
            ['sidebars',                               'Sidebars',              null,                               'layout-sidebar-inactive', 'layouts',   1,   false, true,  null,  null],
            ['pages:layouts-sidebar-light',            'Light Menu',            '/layouts/sidebar-light',           null,                 'sidebars',       0,   false, true,  null,  null],
            ['pages:layouts-sidebar-gradient',         'Gradient Menu',         '/layouts/sidebar-gradient',        null,                 'sidebars',       1,   false, true,  null,  null],
            ['pages:layouts-sidebar-gray',             'Gray Menu',             '/layouts/sidebar-gray',            null,                 'sidebars',       2,   false, true,  null,  null],
            ['pages:layouts-sidebar-image',            'Image Menu',            '/layouts/sidebar-image',           null,                 'sidebars',       3,   false, true,  null,  null],
            ['pages:layouts-sidebar-compact',          'Compact Menu',          '/layouts/sidebar-compact',         null,                 'sidebars',       4,   false, true,  null,  null],
            ['pages:layouts-sidebar-on-hover',         'On Hover Menu',         '/layouts/sidebar-on-hover',        null,                 'sidebars',       5,   false, true,  null,  null],
            ['pages:layouts-sidebar-on-hover-active',  'On Hover Active',       '/layouts/sidebar-on-hover-active', null,                 'sidebars',       6,   false, true,  null,  null],
            ['pages:layouts-sidebar-offcanvas',        'Offcanvas Menu',        '/layouts/sidebar-offcanvas',       null,                 'sidebars',       7,   false, true,  null,  null],
            ['pages:layouts-sidebar-no-icons',         'No Icons with Lines',   '/layouts/sidebar-no-icons',        null,                 'sidebars',       8,   false, true,  null,  null],
            ['pages:layouts-sidebar-with-lines',       'Sidebar with Lines',    '/layouts/sidebar-with-lines',      null,                 'sidebars',       9,   false, true,  null,  null],
            ['topbar',                                 'Topbar',                null,                               'layout-bottombar',   'layouts',        2,   false, true,  null,  null],
            ['pages:layouts-topbar-dark',              'Dark Topbar',           '/layouts/topbar-dark',             null,                 'topbar',         0,   false, true,  null,  null],
            ['pages:layouts-topbar-gray',              'Gray Topbar',           '/layouts/topbar-gray',             null,                 'topbar',         1,   false, true,  null,  null],
            ['pages:layouts-topbar-gradient',          'Gradient Topbar',       '/layouts/topbar-gradient',         null,                 'topbar',         2,   false, true,  null,  null],

            // ── COMPONENTS ────────────────────────────────────────────────────
            ['components',                             'Components',            null,                               'components',         null,             4,   true,  true,  null,  null],

            // Base UI
            ['base-ui',                                'Base UI',               null,                               'components',         'components',     0,   false, true,  null,  null],
            ['pages:ui-accordions',                    'Accordions',            '/ui/accordions',                   null,                 'base-ui',        0,   false, true,  null,  null],
            ['pages:ui-alerts',                        'Alerts',                '/ui/alerts',                       null,                 'base-ui',        1,   false, true,  null,  null],
            ['pages:ui-images',                        'Images',                '/ui/images',                       null,                 'base-ui',        2,   false, true,  null,  null],
            ['pages:ui-badges',                        'Badges',                '/ui/badges',                       null,                 'base-ui',        3,   false, true,  null,  null],
            ['pages:ui-breadcrumb',                    'Breadcrumb',            '/ui/breadcrumb',                   null,                 'base-ui',        4,   false, true,  null,  null],
            ['pages:ui-buttons',                       'Buttons',               '/ui/buttons',                      null,                 'base-ui',        5,   false, true,  null,  null],
            ['pages:ui-cards',                         'Cards',                 '/ui/cards',                        null,                 'base-ui',        6,   false, true,  null,  null],
            ['pages:ui-carousel',                      'Carousel',              '/ui/carousel',                     null,                 'base-ui',        7,   false, true,  null,  null],
            ['pages:ui-collapse',                      'Collapse',              '/ui/collapse',                     null,                 'base-ui',        8,   false, true,  null,  null],
            ['pages:ui-colors',                        'Colors',                '/ui/colors',                       null,                 'base-ui',        9,   false, true,  null,  null],
            ['pages:ui-dropdowns',                     'Dropdowns',             '/ui/dropdowns',                    null,                 'base-ui',        10,  false, true,  null,  null],
            ['pages:ui-videos',                        'Videos',                '/ui/videos',                       null,                 'base-ui',        11,  false, true,  null,  null],
            ['pages:ui-grid',                          'Grid Options',          '/ui/grid',                         null,                 'base-ui',        12,  false, true,  null,  null],
            ['pages:ui-links',                         'Links',                 '/ui/links',                        null,                 'base-ui',        13,  false, true,  null,  null],
            ['pages:ui-list-group',                    'List Group',            '/ui/list-group',                   null,                 'base-ui',        14,  false, true,  null,  null],
            ['pages:ui-modals',                        'Modals',                '/ui/modals',                       null,                 'base-ui',        15,  false, true,  null,  null],
            ['pages:ui-notifications',                 'Notifications',         '/ui/notifications',                null,                 'base-ui',        16,  false, true,  null,  null],
            ['pages:ui-offcanvas',                     'Offcanvas',             '/ui/offcanvas',                    null,                 'base-ui',        17,  false, true,  null,  null],
            ['pages:ui-placeholders',                  'Placeholders',          '/ui/placeholders',                 null,                 'base-ui',        18,  false, true,  null,  null],
            ['pages:ui-pagination',                    'Pagination',            '/ui/pagination',                   null,                 'base-ui',        19,  false, true,  null,  null],
            ['pages:ui-popovers',                      'Popovers',              '/ui/popovers',                     null,                 'base-ui',        20,  false, true,  null,  null],
            ['pages:ui-progress',                      'Progress',              '/ui/progress',                     null,                 'base-ui',        21,  false, true,  null,  null],
            ['pages:ui-spinners',                      'Spinners',              '/ui/spinners',                     null,                 'base-ui',        22,  false, true,  null,  null],
            ['pages:ui-tabs',                          'Tabs',                  '/ui/tabs',                         null,                 'base-ui',        23,  false, true,  null,  null],
            ['pages:ui-tooltips',                      'Tooltips',              '/ui/tooltips',                     null,                 'base-ui',        24,  false, true,  null,  null],
            ['pages:ui-typography',                    'Typography',            '/ui/typography',                   null,                 'base-ui',        25,  false, true,  null,  null],
            ['pages:ui-utilities',                     'Utilities',             '/ui/utilities',                    null,                 'base-ui',        26,  false, true,  null,  null],

            ['pages:widgets',                          'Widgets',               '/widgets',                         'stack-2',            'components',     1,   false, true,  null,  null],
            ['pages:metrics',                          'Metrics',               '/metrics',                         'chart-histogram',    'components',     2,   false, true,  null,  null],

            // Charts
            ['charts',                                 'Charts',                null,                               'chart-donut',        'components',     3,   false, true,  null,  null],
            ['apex-charts',                            'Apex Charts',           null,                               null,                 'charts',         0,   false, true,  null,  null],
            ['pages:charts-apex-area',                 'Area',                  '/charts/apex/area',                null,                 'apex-charts',    0,   false, true,  null,  null],
            ['pages:charts-apex-bar',                  'Bar',                   '/charts/apex/bar',                 null,                 'apex-charts',    1,   false, true,  null,  null],
            ['pages:charts-apex-bubble',               'Bubble',                '/charts/apex/bubble',              null,                 'apex-charts',    2,   false, true,  null,  null],
            ['pages:charts-apex-candlestick',          'Candlestick',           '/charts/apex/candlestick',         null,                 'apex-charts',    3,   false, true,  null,  null],
            ['pages:charts-apex-column',               'Column',                '/charts/apex/column',              null,                 'apex-charts',    4,   false, true,  null,  null],
            ['pages:charts-apex-heatmap',              'Heatmap',               '/charts/apex/heatmap',             null,                 'apex-charts',    5,   false, true,  null,  null],
            ['pages:charts-apex-line',                 'Line',                  '/charts/apex/line',                null,                 'apex-charts',    6,   false, true,  null,  null],
            ['pages:charts-apex-mixed',                'Mixed',                 '/charts/apex/mixed',               null,                 'apex-charts',    7,   false, true,  null,  null],
            ['pages:charts-apex-timeline',             'Timeline',              '/charts/apex/timeline',            null,                 'apex-charts',    8,   false, true,  null,  null],
            ['pages:charts-apex-boxplot',              'Boxplot',               '/charts/apex/boxplot',             null,                 'apex-charts',    9,   false, true,  null,  null],
            ['pages:charts-apex-treemap',              'Treemap',               '/charts/apex/treemap',             null,                 'apex-charts',    10,  false, true,  null,  null],
            ['pages:charts-apex-pie',                  'Pie',                   '/charts/apex/pie',                 null,                 'apex-charts',    11,  false, true,  null,  null],
            ['pages:charts-apex-radar',                'Radar',                 '/charts/apex/radar',               null,                 'apex-charts',    12,  false, true,  null,  null],
            ['pages:charts-apex-radialbar',            'RadialBar',             '/charts/apex/radialbar',           null,                 'apex-charts',    13,  false, true,  null,  null],
            ['pages:charts-apex-scatter',              'Scatter',               '/charts/apex/scatter',             null,                 'apex-charts',    14,  false, true,  null,  null],
            ['pages:charts-apex-polar-area',           'Polar Area',            '/charts/apex/polar-area',          null,                 'apex-charts',    15,  false, true,  null,  null],
            ['pages:charts-apex-sparklines',           'Sparklines',            '/charts/apex/sparklines',          null,                 'apex-charts',    16,  false, true,  null,  null],
            ['pages:charts-apex-range',                'Range',                 '/charts/apex/range',               null,                 'apex-charts',    17,  false, true,  null,  null],
            ['pages:charts-apex-funnel',               'Funnel',                '/charts/apex/funnel',              null,                 'apex-charts',    18,  false, true,  null,  null],
            ['pages:charts-apex-slope',                'Slope',                 '/charts/apex/slope',               null,                 'apex-charts',    19,  false, true,  null,  null],
            ['echarts',                                'Echarts',               null,                               null,                 'charts',         1,   false, true,  null,  null],
            ['pages:charts-echart-line',               'Line',                  '/charts/echart/line',              null,                 'echarts',        0,   false, true,  null,  null],
            ['pages:charts-echart-bar',                'Bar',                   '/charts/echart/bar',               null,                 'echarts',        1,   false, true,  null,  null],
            ['pages:charts-echart-pie',                'Pie',                   '/charts/echart/pie',               null,                 'echarts',        2,   false, true,  null,  null],
            ['pages:charts-echart-scatter',            'Scatter',               '/charts/echart/scatter',           null,                 'echarts',        3,   false, true,  null,  null],
            ['pages:charts-echart-geo-map',            'GEO Map',               '/charts/echart/geo-map',           null,                 'echarts',        4,   false, true,  null,  null],
            ['pages:charts-echart-gauge',              'Gauge',                 '/charts/echart/gauge',             null,                 'echarts',        5,   false, true,  null,  null],
            ['pages:charts-echart-candlestick',        'Candlestick',           '/charts/echart/candlestick',       null,                 'echarts',        6,   false, true,  null,  null],
            ['pages:charts-echart-area',               'Area',                  '/charts/echart/area',              null,                 'echarts',        7,   false, true,  null,  null],
            ['pages:charts-echart-radar',              'Radar',                 '/charts/echart/radar',             null,                 'echarts',        8,   false, true,  null,  null],
            ['pages:charts-echart-heatmap',            'Heatmap',               '/charts/echart/heatmap',           null,                 'echarts',        9,   false, true,  null,  null],
            ['pages:charts-echart-other',              'Other',                 '/charts/echart/other',             null,                 'echarts',        10,  false, true,  null,  null],

            // Forms
            ['forms',                                  'Forms',                 null,                               'clipboard-list',     'components',     4,   false, true,  null,  null],
            ['pages:form-elements',                    'Basic Elements',        '/form/elements',                   null,                 'forms',          0,   false, true,  null,  null],
            ['pages:form-pickers',                     'Pickers',               '/form/pickers',                    null,                 'forms',          1,   false, true,  null,  null],
            ['pages:form-select',                      'Select',                '/form/select',                     null,                 'forms',          2,   false, true,  null,  null],
            ['pages:form-validation',                  'Validation',            '/form/validation',                 null,                 'forms',          3,   false, true,  null,  null],
            ['pages:form-wizard',                      'Wizard',                '/form/wizard',                     null,                 'forms',          4,   false, true,  null,  null],
            ['pages:form-fileuploads',                 'File Uploads',          '/form/fileuploads',                null,                 'forms',          5,   false, true,  null,  null],
            ['pages:form-text-editors',                'Text Editors',          '/form/text-editors',               null,                 'forms',          6,   false, true,  null,  null],
            ['pages:form-range-slider',                'Range Slider',          '/form/range-slider',               null,                 'forms',          7,   false, true,  null,  null],
            ['pages:form-layout',                      'Layouts',               '/form/layout',                     null,                 'forms',          8,   false, true,  null,  null],
            ['pages:form-other-plugin',                'Other Plugins',         '/form/other-plugin',               null,                 'forms',          9,   false, true,  null,  null],

            // Tables
            ['tables',                                 'Tables',                null,                               'table-column',       'components',     5,   false, true,  null,  null],
            ['pages:tables-static',                    'Static Tables',         '/tables/static',                   null,                 'tables',         0,   false, true,  null,  null],
            ['pages:tables-custom',                    'Custom Tables',         '/tables/custom',                   null,                 'tables',         1,   false, true,  null,  null],
            ['datatables',                             'DataTables',            null,                               null,                 'tables',         2,   false, true,  '15',  'bg-success text-white'],
            ['pages:tables-datatables-basic',          'Basic',                 '/tables/datatables/basic',         null,                 'datatables',     0,   false, true,  null,  null],
            ['pages:tables-datatables-export-data',    'Export Data',           '/tables/datatables/export-data',   null,                 'datatables',     1,   false, true,  null,  null],
            ['pages:tables-datatables-select',         'Select',                '/tables/datatables/select',        null,                 'datatables',     2,   false, true,  null,  null],
            ['pages:tables-datatables-ajax',           'Ajax',                  '/tables/datatables/ajax',          null,                 'datatables',     3,   false, true,  null,  null],
            ['pages:tables-datatables-javascript',     'Javascript Source',     '/tables/datatables/javascript',    null,                 'datatables',     4,   false, true,  null,  null],
            ['pages:tables-datatables-rendering',      'Data Rendering',        '/tables/datatables/rendering',     null,                 'datatables',     5,   false, true,  null,  null],
            ['pages:tables-datatables-scroll',         'Scroll',                '/tables/datatables/scroll',        null,                 'datatables',     6,   false, true,  null,  null],
            ['pages:tables-datatables-fixed-columns',  'Fixed Columns',         '/tables/datatables/fixed-columns', null,                 'datatables',     7,   false, true,  null,  null],
            ['pages:tables-datatables-fixed-header',   'Fixed Header',          '/tables/datatables/fixed-header',  null,                 'datatables',     8,   false, true,  null,  null],
            ['pages:tables-datatables-columns',        'Show & Hide Column',    '/tables/datatables/columns',       null,                 'datatables',     9,   false, true,  null,  null],
            ['pages:tables-datatables-child-rows',     'Child Rows',            '/tables/datatables/child-rows',    null,                 'datatables',     10,  false, true,  null,  null],
            ['pages:tables-datatables-column-searching','Column Searching',     '/tables/datatables/column-searching', null,              'datatables',     11,  false, true,  null,  null],
            ['pages:tables-datatables-rows-add',       'Add Rows',              '/tables/datatables/rows-add',      null,                 'datatables',     12,  false, true,  null,  null],
            ['pages:tables-datatables-checkbox-select','Checkbox Select',       '/tables/datatables/checkbox-select', null,               'datatables',     13,  false, true,  null,  null],

            // Icons
            ['icons',                                  'Icons',                 null,                               'icons',              'components',     6,   false, true,  null,  null],
            ['pages:icons-tabler',                     'Tabler',                '/icons/tabler',                    null,                 'icons',          0,   false, true,  null,  null],
            ['pages:icons-lucide',                     'Lucide',                '/icons/lucide',                    null,                 'icons',          1,   false, true,  null,  null],
            ['pages:icons-flags',                      'Flags',                 '/icons/flags',                     null,                 'icons',          2,   false, true,  null,  null],

            // Maps
            ['maps',                                   'Maps',                  null,                               'map',                'components',     7,   false, true,  null,  null],
            ['pages:maps-google',                      'Google Maps',           '/maps/google',                     null,                 'maps',           0,   false, true,  null,  null],
            ['pages:maps-vector',                      'Vector Maps',           '/maps/vector',                     null,                 'maps',           1,   false, true,  null,  null],
            ['pages:maps-leaflet',                     'Leaflet Maps',          '/maps/leaflet',                    null,                 'maps',           2,   false, true,  null,  null],

            // ── MENU ITEMS ────────────────────────────────────────────────────
            ['menu-items',                             'Menu Items',            null,                               'sitemap',            null,             5,   true,  true,  null,  null],
            ['menu-levels',                            'Menu Levels',           null,                               'sitemap',            'menu-items',     0,   false, true,  null,  null],
            ['second-level',                           'Second Level',          null,                               null,                 'menu-levels',    0,   false, true,  null,  null],
            ['menu-item-1',                            'Item 2.1',              null,                               null,                 'second-level',   0,   false, true,  null,  null],
            ['menu-item-2',                            'Item 2.2',              null,                               null,                 'second-level',   1,   false, true,  null,  null],
            ['second-level-2',                         'Second Level',          null,                               null,                 'menu-levels',    1,   false, true,  null,  null],
            ['menu-item-3',                            'Item 2.1',              null,                               null,                 'second-level-2', 0,   false, true,  null,  null],
            ['menu-item-4',                            'Item 2.2',              null,                               null,                 'second-level-2', 1,   false, true,  null,  null],
            ['menu-item-5',                            'Item 3.1',              null,                               null,                 'menu-item-4',    0,   false, true,  null,  null],
            ['menu-item-6',                            'Item 3.2',              null,                               null,                 'menu-item-4',    1,   false, true,  null,  null],
            ['disabled-menu',                          'Disabled Menu',         null,                               'ban',                'menu-items',     1,   false, true,  null,  null],
            ['special-menu',                           'Special Menu',          null,                               'star',               'menu-items',     2,   false, true,  null,  null],
        ];
    }

    public function up(): void
    {
        $now = now();

        // Pass 1 — upsert all rows without parent_id
        foreach ($this->items() as $item) {
            [$slug, $label, $url, $icon, , $sortOrder, $isTitle, $isActive, $badgeText, $badgeClass] = $item;

            DB::table('menus')->upsert(
                [
                    'slug'        => $slug,
                    'label'       => $label,
                    'url'         => $url,
                    'icon'        => $icon,
                    'parent_id'   => null,
                    'sort_order'  => $sortOrder,
                    'is_title'    => $isTitle ? 1 : 0,
                    'is_active'   => $isActive ? 1 : 0,
                    'badge_text'  => $badgeText,
                    'badge_class' => $badgeClass,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                ['slug'],                     // unique key
                ['label', 'url', 'icon', 'sort_order', 'is_title', 'is_active', 'badge_text', 'badge_class', 'updated_at']
            );
        }

        // Pass 2 — resolve parent_slug → parent_id
        foreach ($this->items() as $item) {
            [$slug, , , , $parentSlug] = $item;

            if ($parentSlug !== null) {
                $parentId = DB::table('menus')->where('slug', $parentSlug)->value('id');
                if ($parentId) {
                    DB::table('menus')
                        ->where('slug', $slug)
                        ->update(['parent_id' => $parentId, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->items(), 0);

        // Delete leaves first (children before parents) by reversing the list
        foreach (array_reverse($slugs) as $slug) {
            DB::table('menus')->where('slug', $slug)->delete();
        }
    }
};

