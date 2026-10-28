<div class="lavaggio-article-video" data-lavaggio-video data-video-id="{{ $articleVideo['id'] }}" data-video-layout="{{ $articleVideo['layout'] }}">
    <a
        href="{{ $articleVideo['watch_url'] }}"
        class="lavaggio-article-video-poster"
        data-lavaggio-video-open
        aria-label="Pogledaj video: {{ $articleVideoTitle }}"
        aria-haspopup="dialog"
        aria-controls="lavaggio-video-dialog-{{ $post->id }}"
    >
        <img
            src="{{ $coverImageUrl }}"
            alt="{{ $articleVideoTitle }}"
            width="{{ $coverImageWidth }}"
            height="{{ $coverImageHeight }}"
            loading="eager"
            decoding="async"
        >
        <span class="lavaggio-article-video-play" aria-hidden="true"><x-fa-icon name="play" :inline="true" /></span>
        <span class="lavaggio-article-video-caption" aria-hidden="true">Pogledaj video <x-fa-icon name="arrow-up-right" :inline="true" /></span>
    </a>

    <dialog id="lavaggio-video-dialog-{{ $post->id }}" class="lavaggio-video-dialog is-{{ $articleVideo['layout'] }}" data-lavaggio-video-dialog aria-labelledby="lavaggio-video-title-{{ $post->id }}">
        <h2 id="lavaggio-video-title-{{ $post->id }}" class="sr-only">{{ $articleVideoTitle }}</h2>
        <button type="button" class="lavaggio-video-close" data-lavaggio-video-close aria-label="Zatvori video" autofocus><x-fa-icon name="xmark" :inline="true" /></button>
        <div class="lavaggio-video-player" data-lavaggio-video-player></div>
    </dialog>
</div>
