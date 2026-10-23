@if (($related ?? collect())->isNotEmpty())
    <section class="blog-discovery" aria-labelledby="blog-related-title" data-blog-related-articles>
        <header class="blog-discovery-heading">
            <div><span class="blog-discovery-eyebrow">Nastavite istraživati</span><h2 id="blog-related-title">Vezani članci</h2></div>
            <a href="{{ route('blog.index') }}">Svi članci <x-fa-icon name="arrow-right" /></a>
        </header>
        <div class="blog-discovery-grid">
            @foreach ($related as $relatedPost)
                @include('front.partials.herrera-blog-card', ['blogCardPost' => $relatedPost, 'blogCardLocale' => $locale, 'blogCardFallbackLocale' => $fallbackLocale, 'blogCardShowReadMore' => \App\Support\StorefrontTheme::usesLibermediaBrand()])
            @endforeach
        </div>
    </section>
@endif

@if (($popular ?? collect())->isNotEmpty())
    <section class="blog-discovery blog-discovery-popular" aria-labelledby="blog-popular-title" data-blog-popular-articles>
        <header class="blog-discovery-heading">
            <div><span class="blog-discovery-eyebrow"><x-fa-icon name="book-open" /> Inspiracija koju volite</span><h2 id="blog-popular-title">Najpopularniji članci</h2></div>
            <p>Najčitanije ideje i priče iz našeg bloga.</p>
        </header>
        <div class="blog-discovery-grid">
            @foreach ($popular as $popularPost)
                @include('front.partials.herrera-blog-card', ['blogCardPost' => $popularPost, 'blogCardLocale' => $locale, 'blogCardFallbackLocale' => $fallbackLocale, 'blogCardShowReadMore' => \App\Support\StorefrontTheme::usesLibermediaBrand()])
            @endforeach
        </div>
    </section>
@endif
