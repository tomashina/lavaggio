<?php

namespace Tests\Unit;

use App\Services\Import\LavaggioModxSnapshotNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LavaggioModxSnapshotNormalizerTest extends TestCase
{
    public function test_string_ids_and_templates_preserve_blog_video_and_build_only_genuine_video_covers(): void
    {
        $snapshot = $this->normalize([
            $this->resource('344', '21', ['content' => '<p>Izvorni puni tekst s [[~69]] poveznicom.</p>', 'tvs' => ['videoembed' => '<iframe src="https://www.youtube.com/embed/ivcvfQPdUFQ"></iframe>', 'tag' => 'O praonici']]),
            $this->resource('345', '21', ['tvs' => ['videoembed' => '<iframe src="https://example.com/embed/ivcvfQPdUFQ"></iframe>']]),
        ]);

        $post = $snapshot['resources'][0];
        $this->assertSame(344, $post['id']);
        $this->assertSame('blog', $post['type']);
        $this->assertSame('https://img.youtube.com/vi/ivcvfQPdUFQ/hqdefault.jpg', $post['cover_url']);
        $this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/ivcvfQPdUFQ"></iframe>', $post['body_html']);
        $this->assertStringContainsString('Izvorni puni tekst s [[~69]]', $post['body_html']);
        $this->assertSame('O praonici', $post['tvs']['tag']);
        $this->assertSame('', $snapshot['resources'][1]['cover_url']);
        $this->assertContains('img.youtube.com', $snapshot['allowed_asset_hosts']);
    }

    public function test_migx_prices_and_reviews_keep_every_row_and_decode_extra_escape_layers(): void
    {
        $prices = [
            ['MIGX_id' => '1', 'title' => '\u010cišćenje', 'subtitle' => 'Minimalno 5 kg', 'price' => '6,50 € / kg', 'listitem_1' => 'Pranje i sušenje'],
            ['MIGX_id' => '2', 'title' => '', 'price' => 'Veliki format: 35 € / kom'],
        ];
        $reviews = [['MIGX_id' => '1', 'name' => 'Ana & Ivan', 'comment' => 'Sve je čisto. "Hvala!"']];
        $snapshot = $this->normalize([
            $this->resource('88', '6', ['content' => '<p>Napomena: PDV je uključen.</p>', 'tvs' => ['cjenik' => json_encode($prices)]]),
            $this->resource('78', '24', ['tvs' => ['reviews' => json_encode($reviews)]]),
        ]);

        $body = $snapshot['resources'][0]['body_html'];
        $this->assertStringContainsString('Čišćenje', $body);
        $this->assertStringContainsString('6,50 € / kg', $body);
        $this->assertStringContainsString('Veliki format: 35 € / kom', $body);
        $this->assertStringContainsString('<li>Pranje i sušenje</li>', $body);
        $this->assertStringContainsString('Napomena: PDV je uključen.', $body);
        $this->assertSame(3, substr_count($body, '<tr>'));
        $this->assertStringContainsString('Ana &amp; Ivan', $snapshot['resources'][1]['body_html']);
        $this->assertStringContainsString('Sve je čisto. &quot;Hvala!&quot;', $snapshot['resources'][1]['body_html']);
        $this->assertSame(json_encode($prices), $snapshot['resources'][0]['tvs']['cjenik']);
    }

    public function test_verified_high_resolution_video_cover_replaces_low_resolution_default_and_survives_normalization(): void
    {
        $videoId = 'ivcvfQPdUFQ';
        $cover = ['url' => 'https://img.youtube.com/vi/'.$videoId.'/oardefault.jpg', 'width' => 1080, 'height' => 1920];
        $raw = [
            'resources' => [$this->resource('344', '21', [
                'cover_url' => 'https://img.youtube.com/vi/'.$videoId.'/hqdefault.jpg',
                'tvs' => ['videoembed' => '<iframe src="https://www.youtube.com/embed/'.$videoId.'"></iframe>'],
            ])],
            'verified_video_covers' => [$videoId => $cover],
        ];
        $normalizer = new LavaggioModxSnapshotNormalizer;
        $snapshot = $normalizer->normalize($raw);
        $this->assertSame($cover['url'], $snapshot['resources'][0]['cover_url']);
        $this->assertSame([$videoId => $cover], $snapshot['verified_video_covers']);
        $this->assertSame($cover['url'], $normalizer->normalize($snapshot)['resources'][0]['cover_url']);
    }

    public function test_placeholder_or_different_video_cover_cannot_replace_genuine_default(): void
    {
        $videoId = 'ivcvfQPdUFQ';
        $default = 'https://img.youtube.com/vi/'.$videoId.'/hqdefault.jpg';
        foreach ([
            ['url' => 'https://img.youtube.com/vi/'.$videoId.'/maxresdefault.jpg', 'width' => 120, 'height' => 90],
            ['url' => 'https://img.youtube.com/vi/OtherID1234/oardefault.jpg', 'width' => 1080, 'height' => 1920],
        ] as $cover) {
            $snapshot = (new LavaggioModxSnapshotNormalizer)->normalize([
                'resources' => [$this->resource('344', '21', ['tvs' => ['videoembed' => '<iframe src="https://www.youtube.com/embed/'.$videoId.'"></iframe>']])],
                'verified_video_covers' => [$videoId => $cover],
            ]);
            $this->assertSame($default, $snapshot['resources'][0]['cover_url']);
        }
    }

    public function test_global_faq_is_native_and_page_specific_faq_stays_on_its_page(): void
    {
        $questions = [['MIGX_id' => '10', 'question' => 'Gdje nas naći?', 'answer' => '<p><a href=\\"[[~2]]\\">Kontakt</a></p>\\n<p>Žminj \\u010disto.</p>']];
        $snapshot = $this->normalize([
            $this->resource('72', '19', ['content' => '<p>Dodatna pitanja</p>', 'tvs' => ['faq' => json_encode($questions)]]),
            $this->resource('267', '6', ['tvs' => ['faq' => json_encode($questions)]]),
        ]);

        $this->assertSame('72-10', $snapshot['faqs'][0]['id']);
        $this->assertSame('Gdje nas naći?', $snapshot['faqs'][0]['question']);
        $this->assertSame("<p><a href=\"[[~2]]\">Kontakt</a></p>\n<p>Žminj čisto.</p>", $snapshot['faqs'][0]['answer_html']);
        $this->assertSame('<p>Dodatna pitanja</p>', $snapshot['resources'][0]['body_html']);
        $this->assertStringContainsString('<details><summary>Gdje nas naći?</summary>', $snapshot['resources'][1]['body_html']);
        $this->assertStringContainsString('href="[[~2]]"', $snapshot['resources'][1]['body_html']);
        $this->assertStringNotContainsString('\\n', $snapshot['resources'][1]['body_html']);
    }

    public function test_native_routes_are_mapped_and_empty_groups_link_to_published_children_in_order(): void
    {
        $snapshot = $this->normalize([
            $this->resource('1', '23'),
            $this->resource('2', '3'),
            $this->resource('52', '18'),
            $this->resource('184', '25'),
            $this->resource('192', '0'),
            $this->resource('68', '0'),
            $this->resource('73', '6', ['parent' => '68', 'menuindex' => '1', 'pagetitle' => 'Uslužna praonica']),
            $this->resource('69', '6', ['parent' => '68', 'menuindex' => '0', 'pagetitle' => 'Samoposlužna praonica']),
            $this->resource('74', '6', ['parent' => '68', 'published' => '0', 'pagetitle' => 'Skrivena']),
        ]);

        $this->assertSame([68, 73, 69], array_column($snapshot['resources'], 'id'));
        $this->assertCount(9, $snapshot['source_resources']);
        $this->assertSame('/', $snapshot['url_overrides'][1]);
        $this->assertSame('/contact', $snapshot['url_overrides'][2]);
        $this->assertSame('/blog', $snapshot['url_overrides'][52]);
        $this->assertSame('/faq', $snapshot['url_overrides'][72]);
        $this->assertSame('/contact', $snapshot['url_overrides'][184]);
        $this->assertSame('<ul><li><a href="[[~69]]">Samoposlužna praonica</a></li><li><a href="[[~73]]">Uslužna praonica</a></li></ul>', $snapshot['resources'][0]['body_html']);
    }

    public function test_offline_image_mapping_and_explicit_inactive_source_ids_survive_repeated_normalization(): void
    {
        $localAssets = ['https://www.lavaggio.hr/assets/photo.jpg' => 'assets/lavaggio/content/photo.jpg'];
        $snapshot = (new LavaggioModxSnapshotNormalizer)->normalize([
            'resources' => [$this->resource('69', '6'), $this->resource('999', '6')],
            'inactive_resource_ids' => ['77', '999', '0', '999'],
            'local_assets' => $localAssets,
        ]);
        $repeated = (new LavaggioModxSnapshotNormalizer)->normalize($snapshot);

        $this->assertSame([57, 58, 74, 77, 79, 89, 339, 999], $snapshot['inactive_resource_ids']);
        $this->assertSame([69], array_column($snapshot['resources'], 'id'));
        $this->assertSame($localAssets, $snapshot['local_assets']);
        $this->assertSame($snapshot['inactive_resource_ids'], $repeated['inactive_resource_ids']);
        $this->assertSame($localAssets, $repeated['local_assets']);
    }

    public function test_page_lead_appears_once_after_cover_before_content_and_keeps_source_markup(): void
    {
        $raw = ['resources' => [
            $this->resource('168', '6', [
                'introtext' => 'Naš tim dolazi po <strong>vaše rublje</strong>.',
                'cover_url' => 'https://www.lavaggio.hr/assets/barban.jpg',
                'content' => '<h2>Prikup i dostava</h2><p>Cijeli izvorni sadržaj.</p>',
            ]),
            $this->resource('169', '6', ['introtext' => '<p>Kako radi <em>ozon</em>.</p>', 'content' => '<h2>Postupak</h2>']),
            $this->resource('344', '21', ['introtext' => 'Izvorni sažetak bloga.', 'content' => '<p>Objava</p>']),
        ]];
        $normalizer = new LavaggioModxSnapshotNormalizer;
        $snapshot = $normalizer->normalize($raw);
        $repeated = $normalizer->normalize($snapshot);

        $body = $snapshot['resources'][0]['body_html'];
        $this->assertSame('<figure><img src="https://www.lavaggio.hr/assets/barban.jpg" alt="Izvorna stranica 168" /></figure><p class="lavaggio-content-intro">Naš tim dolazi po <strong>vaše rublje</strong>.</p><h2>Prikup i dostava</h2><p>Cijeli izvorni sadržaj.</p>', $body);
        $this->assertSame(1, substr_count($body, 'Naš tim dolazi po'));
        $this->assertSame($body, $repeated['resources'][0]['body_html']);
        $this->assertSame('<p>Kako radi <em>ozon</em>.</p><h2>Postupak</h2>', $snapshot['resources'][1]['body_html']);
        $this->assertSame('<p>Objava</p>', $snapshot['resources'][2]['body_html']);
    }

    public function test_verified_public_images_take_priority_and_filename_only_images_require_a_verified_base(): void
    {
        $resource = $this->resource('69', '6', ['tvs' => ['blog-image' => 'laundry.jpg']]);
        $withoutBase = $this->normalize([$resource]);
        $this->assertSame('', $withoutBase['resources'][0]['cover_url']);
        $snapshot = (new LavaggioModxSnapshotNormalizer)->normalize([
            'resources' => [$resource],
            'image_base_url' => '/assets/photos',
            'cover_urls' => [69 => '/assets/components/phpthumbof/cache/laundry.verified.webp'],
        ]);
        $this->assertSame('https://www.lavaggio.hr/assets/components/phpthumbof/cache/laundry.verified.webp', $snapshot['resources'][0]['cover_url']);
        $this->assertStringContainsString('<figure><img src="https://www.lavaggio.hr/assets/components/phpthumbof/cache/laundry.verified.webp"', $snapshot['resources'][0]['body_html']);
    }

    public function test_invalid_migx_is_reported_instead_of_silently_omitting_source_content(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid MODX cjenik JSON in resource 88.');
        $this->normalize([$this->resource('88', '6', ['tvs' => ['cjenik' => '{broken']])]);
    }

    private function normalize(array $resources): array
    {
        return (new LavaggioModxSnapshotNormalizer)->normalize(['resources' => $resources]);
    }

    private function resource(string $id, string $template, array $attributes = []): array
    {
        return array_replace([
            'id' => $id, 'parent' => '0', 'template' => $template, 'pagetitle' => 'Izvorna stranica '.$id,
            'menutitle' => '', 'alias' => 'stranica-'.$id, 'uri' => 'usluge/stranica-'.$id,
            'content' => '', 'published' => '1', 'tvs' => [],
        ], $attributes);
    }
}
