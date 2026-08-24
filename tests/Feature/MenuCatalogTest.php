<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_menu_sections_are_not_seeded(): void
    {
        $this->assertSame(
            0,
            DB::table('menus')->whereIn('slug', ['layouts', 'components', 'menu-items'])->count()
        );
        $this->assertSame(
            0,
            DB::table('menus')
                ->where('url', 'like', '/layouts/%')
                ->orWhere('url', '/icons/tabler')
                ->count()
        );
    }

    public function test_custom_pages_and_auth_sections_are_removed(): void
    {
        $this->assertSame(
                0,
                DB::table('menus')->whereIn('slug', ['custom-pages', 'pages:pages-empty', 'authentication', 'error-pages'])->count()
        );
    }

    public function test_customer_menus_are_seeded(): void
    {
        $this->assertSame(7, DB::table('customer_menus')->count());
        $this->assertSame(
                7,
                DB::table('customer_menus')->where('show_in_customer_page', true)->count()
        );
    }
}
