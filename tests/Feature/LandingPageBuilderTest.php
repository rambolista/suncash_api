<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\ProjectSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingPageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_only_selected_enabled_content_in_sort_order(): void
    {
        $page = LandingPage::query()->create([
            'name' => 'Marketing',
            'slug' => 'marketing',
            'is_navigation_fixed' => true,
            'status' => 'published',
            'is_active' => true,
        ]);
        $last = $page->sections()->create([
            'type' => 'footer',
            'title' => 'Last',
            'sort_order' => 20,
        ]);
        $first = $page->sections()->create([
            'type' => 'features',
            'title' => 'First',
            'sort_order' => 10,
        ]);
        $page->sections()->create([
            'type' => 'about',
            'title' => 'Hidden',
            'sort_order' => 0,
            'is_enabled' => false,
        ]);
        $first->items()->create(['title' => 'Second item', 'sort_order' => 20]);
        $first->items()->create(['title' => 'First item', 'sort_order' => 10]);
        $first->items()->create(['title' => 'Hidden item', 'sort_order' => 0, 'is_enabled' => false]);
        $last->items()->create(['title' => 'Footer item']);
        ProjectSetting::query()->findOrFail(1)->update(['landing_page_id' => $page->id]);

        $this->getJson('/api/landing-page')
            ->assertOk()
            ->assertJsonPath('landing_page.slug', 'marketing')
            ->assertJsonPath('landing_page.is_navigation_fixed', true)
            ->assertJsonPath('landing_page.sections.0.title', 'First')
            ->assertJsonPath('landing_page.sections.0.items.0.title', 'First item')
            ->assertJsonPath('landing_page.sections.0.items.1.title', 'Second item')
            ->assertJsonPath('landing_page.sections.1.title', 'Last')
            ->assertJsonCount(2, 'landing_page.sections')
            ->assertJsonCount(2, 'landing_page.sections.0.items');
    }

    public function test_carousel_section_returns_multiple_image_slides_on_the_public_page(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $pageId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/landing-pages', [
                'name' => 'Carousel Page',
                'slug' => 'carousel-page',
                'status' => 'published',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('landing_page.id');

        $sectionResponse = $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections", [
                'type' => 'carousel',
                'title' => 'Featured slides',
                'settings' => json_encode([
                    'carousel_autoplay' => true,
                    'carousel_interval' => 4000,
                    'carousel_fade' => true,
                    'carousel_full_width' => true,
                ]),
            ])
            ->assertCreated()
            ->assertJsonPath('section.type', 'carousel')
            ->assertJsonPath('section.settings.carousel_interval', 4000)
            ->assertJsonPath('section.settings.carousel_full_width', true);
        $sectionId = $sectionResponse->json('section.id');

        foreach (['first-slide.jpg', 'second-slide.jpg'] as $index => $filename) {
            $this->actingAs($admin, 'sanctum')
                ->post("/api/landing-pages/$pageId/sections/$sectionId/items", [
                    'title' => 'Slide '.($index + 1),
                    'sort_order' => $index,
                    'image' => UploadedFile::fake()->image($filename, 1600, 900),
                ])
                ->assertCreated();
        }

        ProjectSetting::query()->findOrFail(1)->update(['landing_page_id' => $pageId]);

        $this->getJson('/api/landing-page')
            ->assertOk()
            ->assertJsonPath('landing_page.sections.0.type', 'carousel')
            ->assertJsonCount(2, 'landing_page.sections.0.items')
            ->assertJsonPath(
                'landing_page.sections.0.items.0.image_url',
                fn ($url) => str_contains($url, '/storage/landing-pages/items/')
            );
    }

    public function test_faq_section_returns_questions_and_answers_on_the_public_page(): void
    {
        $admin = $this->superAdmin();

        $pageId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/landing-pages', [
                'name' => 'FAQ Page',
                'slug' => 'faq-page',
                'status' => 'published',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('landing_page.id');

        $sectionId = $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections", [
                'type' => 'faq',
                'title' => 'Frequently Asked Questions',
                'settings' => json_encode(['faq_open_first' => true]),
            ])
            ->assertCreated()
            ->assertJsonPath('section.type', 'faq')
            ->assertJsonPath('section.settings.faq_open_first', true)
            ->json('section.id');

        $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections/$sectionId/items", [
                'title' => 'How does billing work?',
                'content' => '<p>Billing is processed monthly.</p>',
                'sort_order' => 0,
            ])
            ->assertCreated();

        ProjectSetting::query()->findOrFail(1)->update(['landing_page_id' => $pageId]);

        $this->getJson('/api/landing-page')
            ->assertOk()
            ->assertJsonPath('landing_page.sections.0.type', 'faq')
            ->assertJsonPath('landing_page.sections.0.items.0.title', 'How does billing work?')
            ->assertJsonPath('landing_page.sections.0.items.0.content', '<p>Billing is processed monthly.</p>');
    }

    public function test_super_admin_can_crud_upload_reorder_and_select_a_page(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $pageId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/landing-pages', [
                'name' => 'Campaign',
                'slug' => 'campaign',
                'description' => 'Campaign page',
                'is_navigation_fixed' => true,
                'status' => 'published',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('landing_page.is_navigation_fixed', true)
            ->json('landing_page.id');

        $sectionResponse = $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections", [
                'type' => 'hero',
                'title' => 'Hero',
                'settings' => json_encode(['align' => 'center']),
                'sort_order' => 20,
                'image' => UploadedFile::fake()->image('hero.jpg'),
                'background_image' => UploadedFile::fake()->image('background.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('section.settings.align', 'center');
        $sectionId = $sectionResponse->json('section.id');
        $oldImage = $sectionResponse->json('section.image_path');
        Storage::disk('public')->assertExists($oldImage);
        Storage::disk('public')->assertExists($sectionResponse->json('section.background_image_path'));

        $otherSectionId = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/landing-pages/$pageId/sections", [
                'type' => 'footer',
                'title' => 'Footer',
                'sort_order' => 10,
            ])
            ->assertCreated()
            ->json('section.id');

        $itemResponse = $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections/$sectionId/items", [
                'title' => 'Feature',
                'sort_order' => 20,
                'image' => UploadedFile::fake()->image('feature.png'),
            ])
            ->assertCreated();
        $itemId = $itemResponse->json('item.id');
        Storage::disk('public')->assertExists($itemResponse->json('item.image_path'));

        $this->actingAs($admin, 'sanctum')
            ->post("/api/landing-pages/$pageId/sections/$sectionId", [
                '_method' => 'PATCH',
                'title' => 'Updated hero',
                'image' => UploadedFile::fake()->image('new-hero.jpg'),
            ])
            ->assertOk();
        Storage::disk('public')->assertMissing($oldImage);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/landing-pages/$pageId/sections/reorder", [
                'sections' => [
                    ['id' => $sectionId, 'sort_order' => 0],
                    ['id' => $otherSectionId, 'sort_order' => 10],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('sections.0.id', $sectionId);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/landing-pages/$pageId/sections/$sectionId/items/reorder", [
                'items' => [['id' => $itemId, 'sort_order' => 0]],
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/project-settings', [
                'name' => 'AdminStarterKit',
                'author' => 'BYX',
                'description' => null,
                'authentication_type' => 'basic',
                'customer_authentication_type' => 'basic',
                'landing_page_id' => $pageId,
            ])
            ->assertOk()
            ->assertJsonPath('settings.landing_page_id', $pageId);

        $this->getJson('/api/landing-page')
            ->assertOk()
            ->assertJsonPath('landing_page.sections.0.title', 'Updated hero');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/landing-pages/$pageId/sections/$sectionId/items/$itemId")
            ->assertOk();
        $this->assertDatabaseMissing('landing_page_section_items', ['id' => $itemId]);
    }

    public function test_super_admin_sees_the_php_upload_error_for_invalid_landing_images(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $pageId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/landing-pages', [
                'name' => 'Campaign',
                'slug' => 'campaign',
                'status' => 'published',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('landing_page.id');

        $tempFile = tempnam(sys_get_temp_dir(), 'landing-upload');
        file_put_contents($tempFile, 'partial upload');

        try {
            $this->actingAs($admin, 'sanctum')
                ->post("/api/landing-pages/$pageId/sections", [
                    'type' => 'hero',
                    'title' => 'Hero',
                    'image' => new UploadedFile($tempFile, 'hero.png', 'image/png', UPLOAD_ERR_PARTIAL, true),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image')
                ->assertJsonPath('errors.image.0', 'The image upload failed (PHP UPLOAD_ERR_PARTIAL, code 3): The file "hero.png" was only partially uploaded.');
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_regular_users_are_denied_and_draft_pages_cannot_be_selected(): void
    {
        $user = User::factory()->create();
        $draft = LandingPage::query()->create([
            'name' => 'Draft',
            'slug' => 'draft',
            'status' => 'draft',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/landing-pages')
            ->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/landing-pages', [
                'name' => 'Blocked',
                'slug' => 'blocked',
                'status' => 'published',
            ])
            ->assertForbidden();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson('/api/project-settings', [
                'name' => 'AdminStarterKit',
                'author' => 'BYX',
                'description' => null,
                'authentication_type' => 'basic',
                'customer_authentication_type' => 'basic',
                'landing_page_id' => $draft->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('landing_page_id');
    }

    public function test_super_admin_can_duplicate_a_page_with_independent_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('landing-pages/sections/source.jpg', 'section-image');
        Storage::disk('public')->put('landing-pages/items/source.png', 'item-image');

        $source = LandingPage::query()->create([
            'name' => 'Original',
            'slug' => 'original',
            'description' => 'Original description',
            'is_navigation_fixed' => true,
            'status' => 'published',
            'is_active' => true,
        ]);
        $section = $source->sections()->create([
            'type' => 'features',
            'title' => 'Features',
            'image_path' => 'landing-pages/sections/source.jpg',
            'settings' => ['nav_label' => 'Features'],
            'sort_order' => 10,
        ]);
        $section->items()->create([
            'title' => 'Feature one',
            'image_path' => 'landing-pages/items/source.png',
            'settings' => ['badge' => 'Popular'],
            'sort_order' => 5,
        ]);

        $response = $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/landing-pages/{$source->id}/duplicate", [
                'name' => 'Custom Copy',
                'slug' => 'custom-copy',
            ])
            ->assertCreated()
            ->assertJsonPath('landing_page.name', 'Custom Copy')
            ->assertJsonPath('landing_page.status', 'draft')
            ->assertJsonPath('landing_page.is_active', false)
            ->assertJsonPath('landing_page.is_navigation_fixed', true)
            ->assertJsonPath('landing_page.sections.0.title', 'Features')
            ->assertJsonPath('landing_page.sections.0.items.0.title', 'Feature one');

        $sectionImage = $response->json('landing_page.sections.0.image_path');
        $itemImage = $response->json('landing_page.sections.0.items.0.image_path');
        $this->assertNotSame('landing-pages/sections/source.jpg', $sectionImage);
        $this->assertNotSame('landing-pages/items/source.png', $itemImage);
        Storage::disk('public')->assertExists($sectionImage);
        Storage::disk('public')->assertExists($itemImage);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson("/api/landing-pages/{$source->id}")
            ->assertOk();

        Storage::disk('public')->assertExists($sectionImage);
        Storage::disk('public')->assertExists($itemImage);
    }

    public function test_deleting_or_unpublishing_selected_page_is_safe(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();
        $page = LandingPage::query()->create([
            'name' => 'Selected',
            'slug' => 'selected',
            'status' => 'published',
            'is_active' => true,
        ]);
        $path = UploadedFile::fake()->image('hero.jpg')->store('landing-pages/sections', 'public');
        $page->sections()->create(['type' => 'hero', 'image_path' => $path]);
        ProjectSetting::query()->findOrFail(1)->update(['landing_page_id' => $page->id]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/landing-pages/{$page->id}", ['status' => 'draft'])
            ->assertOk();
        $this->assertNull(ProjectSetting::query()->findOrFail(1)->landing_page_id);

        $page->update(['status' => 'published']);
        ProjectSetting::query()->findOrFail(1)->update(['landing_page_id' => $page->id]);
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/landing-pages/{$page->id}")
            ->assertOk();

        $this->assertNull(ProjectSetting::query()->findOrFail(1)->landing_page_id);
        Storage::disk('public')->assertMissing($path);
        $this->getJson('/api/landing-page')
            ->assertOk()
            ->assertJsonPath('landing_page', null);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['super_admin' => true])->save();

        return $user;
    }
}
