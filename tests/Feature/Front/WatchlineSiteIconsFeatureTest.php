<?php

namespace Tests\Feature\Front;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WatchlineSiteIconsFeatureTest extends TestCase
{
    public function test_the_original_store_name_with_spaces_uses_the_watchline_symbol_instead_of_inherited_icons(): void
    {
        $branding = ['store_name' => 'WATCH LINE AM - Web Shop', 'favicon_url' => '/assets/brand/libermedia-favicon.svg',
            'favicons' => ['ico_url' => '/inherited/favicon.ico', '32_url' => '/inherited/favicon.png']];

        foreach (['watchline', 'default'] as $home) {
            config(['storefront.home' => $home]);
            $html = Blade::render('<x-front.site-icons :branding="$branding" />', compact('branding'));

            foreach (['watchline-favicon.svg', 'watchline-favicon.ico', 'watchline-favicon-16.png', 'watchline-favicon-32.png', 'watchline-apple-touch-icon.png'] as $filename) {
                $this->assertStringContainsString('assets/brand/'.$filename.'?v=', $html);
            }
            $this->assertStringNotContainsString('libermedia-favicon', $html);
            $this->assertStringNotContainsString('/inherited/', $html);
        }
    }

    public function test_configured_watchline_home_keeps_the_symbol_when_the_display_name_is_edited(): void
    {
        config(['storefront.home' => 'watchline']);
        $branding = ['store_name' => 'Web Shop', 'favicon_url' => '/assets/brand/libermedia-favicon.svg'];
        $html = Blade::render('<x-front.site-icons :branding="$branding" />', compact('branding'));

        $this->assertStringContainsString('assets/brand/watchline-favicon.svg?v=', $html);
        $this->assertStringNotContainsString('libermedia-favicon', $html);
    }
}
