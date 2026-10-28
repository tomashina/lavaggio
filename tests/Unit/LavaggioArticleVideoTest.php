<?php

namespace Tests\Unit;

use App\Support\LavaggioArticleVideo;
use PHPUnit\Framework\TestCase;

class LavaggioArticleVideoTest extends TestCase
{
    public function test_video_is_extracted_without_changing_any_original_article_text_or_markup(): void
    {
        $before = '<p>Čisto <strong>rublje</strong> &amp; njega.</p>'."\r\n";
        $frame = '<iframe width="315" height="560" src="https://www.youtube-nocookie.com/embed/yOjuuBBVdmc?rel=0" title="Video > sadržaj"></iframe>';
        $after = '<p><a href="/page/ozonsko-pranje-rublja">Ozonsko pranje</a><br>Izvorni sadržaj.</p>';

        $video = LavaggioArticleVideo::extract($before.$frame.$after);

        $this->assertSame('yOjuuBBVdmc', $video['id']);
        $this->assertSame('portrait', $video['layout']);
        $this->assertSame($before.$after, $video['body_html']);
        $this->assertSame('https://www.youtube.com/watch?v=yOjuuBBVdmc', $video['watch_url']);
    }

    public function test_landscape_video_keeps_its_ratio_and_only_the_selected_frame_is_removed(): void
    {
        $frame = "<iframe src='https://www.youtube.com/embed/Abcdef12345' width='560' height='315'></iframe>";
        $body = $frame.'<p>Prvi i drugi video.</p>'.$frame;
        $video = LavaggioArticleVideo::extract($body);

        $this->assertSame('landscape', $video['layout']);
        $this->assertSame('<p>Prvi i drugi video.</p>'.$frame, $video['body_html']);
    }

    public function test_non_youtube_and_invalid_sources_never_become_modal_players(): void
    {
        foreach ([
            'https://youtube.com.evil.test/embed/Abcdef12345',
            'https://evil.test/embed/Abcdef12345',
            'javascript:alert(1)',
            '//www.youtube.com/embed/Abcdef12345',
            'http://www.youtube.com/embed/Abcdef12345',
            'https://name@www.youtube.com/embed/Abcdef12345',
            'https://www.youtube.com:443/embed/Abcdef12345',
            'https://www.youtube.com/embed/invalid',
            'https://www.youtube.com/embed/Abcdef12345/extra',
        ] as $source) {
            $this->assertNull(LavaggioArticleVideo::extract('<iframe src="'.$source.'"></iframe><p>Tekst.</p>'), $source);
        }
        $this->assertNull(LavaggioArticleVideo::extract('<p>Članak bez videa.</p>'));
    }
}
