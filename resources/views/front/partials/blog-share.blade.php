@php
    $articleUrl = route('blog.show', ['slug' => $translation?->slug]);
    $articleTitle = (string) ($translation?->title ?? $post->code);
    $encodedArticleUrl = rawurlencode($articleUrl);
@endphp
<aside class="blog-share" aria-label="Podijelite članak" data-blog-share>
    <div class="blog-share-label">
        <span>Inspiracija je ljepša kad se dijeli.</span>
        <strong>Podijelite članak</strong>
    </div>
    <div class="blog-share-actions">
        <a class="blog-share-button" href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedArticleUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Podijelite na Facebooku" title="Facebook"><x-fa-icon name="facebook-f" style="brands" /></a>
        <a class="blog-share-button" href="https://wa.me/?text={{ rawurlencode($articleTitle.' '.$articleUrl) }}" target="_blank" rel="noopener noreferrer" aria-label="Podijelite na WhatsAppu" title="WhatsApp"><x-fa-icon name="whatsapp" style="brands" /></a>
        <a class="blog-share-button" href="mailto:?subject={{ rawurlencode($articleTitle) }}&amp;body={{ $encodedArticleUrl }}" aria-label="Podijelite e-poštom" title="E-pošta"><x-fa-icon name="envelope" /></a>
        <button class="blog-share-button blog-share-copy" type="button" data-blog-copy-url="{{ $articleUrl }}" aria-label="Kopirajte poveznicu članka" title="Kopiraj poveznicu"><x-fa-icon name="link" /><span>Kopiraj poveznicu</span></button>
    </div>
    <p class="blog-share-feedback" role="status" aria-live="polite" data-blog-share-feedback></p>
    <label class="blog-share-fallback" hidden data-blog-share-fallback>Poveznica članka<input type="text" readonly value="{{ $articleUrl }}" aria-label="Poveznica članka za kopiranje"></label>
</aside>
