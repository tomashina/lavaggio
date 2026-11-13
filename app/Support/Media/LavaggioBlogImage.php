<?php

namespace App\Support\Media;

use App\Models\Content\Blog\BlogPost;
use App\Support\LavaggioArticleVideo;

class LavaggioBlogImage
{
    /** Resolve from the current video, so new posts and video edits need no manual cover upload. */
    public static function first(BlogPost $post, ?array $video): ?string
    {
        if ($video === null) {
            return HerreraBlogImage::first($post);
        }

        foreach ($post->getMedia('blog_cover') as $media) {
            if (! MediaUrl::hasUsableOriginal($media)) {
                continue;
            }

            $source = (string) $media->getCustomProperty('lavaggio_source_url', '');
            // Imported YouTube covers belong to their original video. A changed
            // video must not keep that old image; manually uploaded covers remain usable.
            if (preg_match('~\Ahttps://(?:img\.youtube\.com|i\.ytimg\.com)/vi/([A-Za-z0-9_-]{11})/~', $source, $match)
                && $match[1] !== $video['id']) {
                continue;
            }

            return (string) $media->getUrl();
        }

        return LavaggioArticleVideo::thumbnailUrl($video);
    }
}
