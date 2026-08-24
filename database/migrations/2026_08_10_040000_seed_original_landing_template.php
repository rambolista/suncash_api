<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('landing_pages')->where('slug', 'default')->first();
        if (! $page || DB::table('landing_page_sections')->where('landing_page_id', $page->id)->count() !== 2) {
            return;
        }

        DB::transaction(function () use ($page) {
            DB::table('landing_page_sections')->where('landing_page_id', $page->id)->delete();
            $now = now();

            $addSection = function (array $section) use ($page, $now): int {
                $section['landing_page_id'] = $page->id;
                $section['settings'] = json_encode($section['settings'] ?? [], JSON_THROW_ON_ERROR);
                $section['is_enabled'] = true;
                $section['created_at'] = $now;
                $section['updated_at'] = $now;

                return DB::table('landing_page_sections')->insertGetId($section);
            };

            $addItems = function (int $sectionId, array $items) use ($now): void {
                foreach ($items as $sortOrder => $item) {
                    $item['landing_page_section_id'] = $sectionId;
                    $item['settings'] = json_encode($item['settings'] ?? [], JSON_THROW_ON_ERROR);
                    $item['sort_order'] = $sortOrder;
                    $item['is_enabled'] = true;
                    $item['created_at'] = $now;
                    $item['updated_at'] = $now;
                    DB::table('landing_page_section_items')->insert($item);
                }
            };

            $addSection([
                'type' => 'hero',
                'title' => 'The #1 Admin Dashboard Template — Trusted by Thousands',
                'subtitle' => 'Trusted by 50k+ happy customers',
                'content' => 'Build powerful, modern web applications with our top-rated Admin Dashboard Template. Designed for performance, flexibility, and easy customization.',
                'primary_link_label' => 'Get Started',
                'primary_link_url' => '/auth/sign-up',
                'secondary_link_label' => 'Contact Us',
                'secondary_link_url' => '#contact',
                'sort_order' => 0,
                'settings' => [
                    'nav_label' => 'Home',
                    'alignment' => 'center',
                    'default_image' => 'dashboard',
                    'show_trusted_avatars' => true,
                ],
            ]);

            $chat = $addSection([
                'type' => 'about',
                'title' => 'Connecting conversations across the world',
                'subtitle' => 'Fast, secure, and intuitive',
                'content' => 'Our chat platform empowers teams and communities to communicate effortlessly, no matter the distance.',
                'primary_link_label' => 'Check Chat App',
                'primary_link_url' => '/apps/chat',
                'sort_order' => 10,
                'settings' => ['nav_label' => 'Features', 'default_image' => 'chat', 'image_position' => 'left'],
            ]);
            $addItems($chat, [
                ['title' => '99.2%', 'subtitle' => 'User satisfaction'],
                ['title' => '7.4x', 'subtitle' => 'Monthly user growth'],
                ['title' => '1200+', 'subtitle' => 'Messages sent per second'],
            ]);

            $files = $addSection([
                'type' => 'about',
                'title' => 'Manage your files seamlessly from anywhere',
                'subtitle' => 'Secure storage and collaboration',
                'content' => 'Access files instantly, collaborate in real time, and enjoy peace of mind with encrypted storage.',
                'primary_link_label' => 'Explore File Manager',
                'primary_link_url' => '/apps/file-manager',
                'sort_order' => 20,
                'settings' => ['nav_label' => '', 'default_image' => 'file-manager', 'image_position' => 'right'],
            ]);
            $addItems($files, [
                ['title' => '99.5%', 'subtitle' => 'File recovery success'],
                ['title' => '3.2x', 'subtitle' => 'Faster upload speed'],
                ['title' => '1500+', 'subtitle' => 'Files organized per minute'],
            ]);

            $contacts = $addSection([
                'type' => 'about',
                'title' => 'Manage your connections with ease',
                'subtitle' => 'Organized, accessible, and synchronized',
                'content' => 'Keep all your relationships organized and in sync across devices through a clean, privacy-focused interface.',
                'primary_link_label' => 'Check Contacts App',
                'primary_link_url' => '/apps/users/contacts',
                'sort_order' => 30,
                'settings' => ['nav_label' => '', 'default_image' => 'team', 'image_position' => 'left'],
            ]);
            $addItems($contacts, [
                ['title' => '97.5%', 'subtitle' => 'Sync reliability'],
                ['title' => '4.2x', 'subtitle' => 'Faster contact search'],
                ['title' => '250k+', 'subtitle' => 'Contacts managed daily'],
            ]);

            $services = $addSection([
                'type' => 'features',
                'title' => 'Discover Our Core Services and Capabilities',
                'subtitle' => 'Empowering your digital journey',
                'content' => 'Flexible services that help teams launch, grow, and secure modern products.',
                'sort_order' => 40,
                'settings' => ['nav_label' => 'Services', 'alignment' => 'center'],
            ]);
            $addItems($services, [
                ['title' => 'Digital Strategy', 'content' => 'Crafting data-driven strategies to maximize online growth and brand engagement.', 'icon' => 'bulb', 'link_label' => 'Know more', 'link_url' => '#contact'],
                ['title' => 'SEO Optimization', 'content' => 'Improve search rankings and website visibility through tailored SEO practices.', 'icon' => 'chart-bar', 'link_label' => 'Know more', 'link_url' => '#contact'],
                ['title' => 'Web Development', 'content' => 'Building fast, responsive, and scalable websites that meet your business needs.', 'icon' => 'world', 'link_label' => 'Know more', 'link_url' => '#contact'],
                ['title' => 'Security Audits', 'content' => 'Protect your website and data with comprehensive vulnerability assessments.', 'icon' => 'shield-check', 'link_label' => 'Know more', 'link_url' => '#contact'],
            ]);

            $pricing = $addSection([
                'type' => 'pricing',
                'title' => 'Choose the License That Fits Your Needs',
                'subtitle' => 'Transparent and flexible pricing',
                'sort_order' => 50,
                'settings' => ['nav_label' => 'Plans', 'alignment' => 'center'],
            ]);
            $addItems($pricing, [
                ['title' => 'Single License', 'subtitle' => 'Perfect for personal or one-client projects', 'content' => "1 project usage\nFull component access\nBasic documentation", 'link_label' => 'Buy Single License', 'link_url' => '#contact', 'settings' => ['price' => '$49']],
                ['title' => 'Multiple License', 'subtitle' => 'For agencies working with multiple clients', 'content' => "Use in up to 5 projects\nCommercial client use\nLifetime updates\nPremium support", 'link_label' => 'Buy Multiple License', 'link_url' => '#contact', 'settings' => ['price' => '$249', 'badge' => 'Best Value']],
                ['title' => 'Extended License', 'subtitle' => 'For SaaS products and paid applications', 'content' => "Unlimited project usage\nSaaS and resale rights\nPriority support", 'link_label' => 'Buy Extended License', 'link_url' => '#contact', 'settings' => ['price' => '$999']],
            ]);

            $reviews = $addSection([
                'type' => 'testimonials',
                'title' => 'Real Feedback from Satisfied Clients',
                'subtitle' => 'What our customers are saying',
                'sort_order' => 60,
                'settings' => ['nav_label' => 'Reviews', 'alignment' => 'center'],
            ]);
            $addItems($reviews, [
                ['title' => 'Absolutely love it!', 'subtitle' => 'Emily Carter', 'content' => 'This product exceeded all my expectations. Sleek design and incredible performance!', 'settings' => ['default_image' => 'user-1']],
                ['title' => 'Great value for money', 'subtitle' => 'Michael Zhang', 'content' => 'Sturdy, reliable, and easy to customize. I would definitely recommend it.', 'settings' => ['default_image' => 'user-2']],
                ['title' => 'Top-notch customer service', 'subtitle' => 'Sara Lopez', 'content' => 'The team helped me right away and the entire experience was smooth.', 'settings' => ['default_image' => 'user-3']],
            ]);

            $blog = $addSection([
                'type' => 'blog',
                'title' => 'Explore Our Latest Articles and Updates',
                'subtitle' => 'Insights and resources',
                'sort_order' => 70,
                'settings' => ['nav_label' => 'Blog', 'alignment' => 'center'],
            ]);
            $addItems($blog, [
                ['title' => 'The Future of Artificial Intelligence', 'subtitle' => 'Technology', 'content' => 'Discover how AI is transforming industries and what the future holds.', 'link_label' => 'Read more', 'link_url' => '#contact', 'settings' => ['default_image' => 'blog-4', 'meta' => 'Jan 12, 2025']],
                ['title' => 'Top Data Science Trends', 'subtitle' => 'Data Science', 'content' => 'Get ahead with the latest technologies and tools reshaping the industry.', 'link_label' => 'Read more', 'link_url' => '#contact', 'settings' => ['default_image' => 'blog-5', 'meta' => 'Jan 12, 2025']],
                ['title' => '5 Key Tips for New Entrepreneurs', 'subtitle' => 'Business', 'content' => 'Essential tips to guide you through the first year of business.', 'link_label' => 'Read more', 'link_url' => '#contact', 'settings' => ['default_image' => 'blog-3', 'meta' => 'Jan 12, 2025']],
            ]);

            $contact = $addSection([
                'type' => 'contact',
                'title' => 'Get in Touch with Our Team',
                'subtitle' => "We're here to help",
                'content' => 'Tell us about your project and our team will get back to you.',
                'primary_link_label' => 'Send Message',
                'sort_order' => 80,
                'settings' => ['nav_label' => 'Contact', 'alignment' => 'center'],
            ]);
            $addItems($contact, [
                ['title' => 'Contact Numbers', 'content' => "+1 (800) 123-4567\n+1 (415) 987-6543", 'icon' => 'phone-call'],
                ['title' => 'Email', 'content' => "support@yourcompany.com\ninfo@yourcompany.com", 'icon' => 'mail'],
                ['title' => 'Address', 'content' => '123 Market Street, San Francisco, CA 94103', 'icon' => 'map-pin'],
            ]);

            $addSection([
                'type' => 'cta',
                'title' => 'Build Faster with Our Premium Admin Template',
                'subtitle' => 'Kickstart your project with a modern, responsive, and developer-friendly dashboard.',
                'primary_link_label' => 'Get Started',
                'primary_link_url' => '/auth/sign-up',
                'sort_order' => 90,
                'settings' => ['nav_label' => '', 'alignment' => 'center', 'default_background' => 'landing-cta'],
            ]);

            $footer = $addSection([
                'type' => 'footer',
                'title' => 'AdminStarterKit',
                'content' => 'A reusable responsive Bootstrap 5 admin foundation for modern applications.',
                'sort_order' => 100,
                'settings' => ['nav_label' => ''],
            ]);
            $addItems($footer, [
                ['title' => 'Company', 'link_label' => 'Our Story', 'link_url' => '#landing-navbar'],
                ['title' => 'Product', 'link_label' => 'Features', 'link_url' => '#features'],
                ['title' => 'Support', 'link_label' => 'Contact Us', 'link_url' => '#contact'],
            ]);
        });
    }

    public function down(): void
    {
        $page = DB::table('landing_pages')->where('slug', 'default')->first();
        if (! $page) {
            return;
        }

        if (DB::table('landing_page_sections')->where('landing_page_id', $page->id)->count() !== 11) {
            throw new RuntimeException('The original landing template has been customized and cannot be safely rolled back.');
        }

        DB::transaction(function () use ($page) {
            DB::table('landing_page_sections')->where('landing_page_id', $page->id)->delete();
            $now = now();
            DB::table('landing_page_sections')->insert([
                [
                    'landing_page_id' => $page->id,
                    'type' => 'hero',
                    'title' => 'AdminStarterKit',
                    'subtitle' => 'Build administration experiences faster.',
                    'content' => 'A reusable responsive Bootstrap 5 admin foundation.',
                    'primary_link_label' => 'Get Started',
                    'primary_link_url' => '/auth/sign-in',
                    'sort_order' => 0,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'landing_page_id' => $page->id,
                    'type' => 'footer',
                    'title' => 'AdminStarterKit',
                    'subtitle' => null,
                    'content' => 'Built by BYX.',
                    'primary_link_label' => null,
                    'primary_link_url' => null,
                    'sort_order' => 100,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
};
