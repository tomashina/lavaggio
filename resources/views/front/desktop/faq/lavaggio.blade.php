<section class="lavaggio-faq-page">
    <header class="lavaggio-faq-title lm-page-title">
        <nav aria-label="Breadcrumb">
            <ol>
                <li><a href="{{ route('home') }}"><x-front.breadcrumb-home /></a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">{{ $faqTitle }}</li>
            </ol>
        </nav>
        <h1>{{ $faqTitle }}</h1>
    </header>

    <section class="lavaggio-faq-list" aria-label="{{ $faqTitle }}">
        @forelse ($faqs as $faq)
            @php
                $translation = $faq->translations->firstWhere('locale', $locale)
                    ?? $faq->translations->firstWhere('locale', $fallbackLocale)
                    ?? $faq->translations->first();
            @endphp
            @if ($translation)
                <details class="faq-accordion-item lavaggio-faq-item" data-lavaggio-faq-item>
                    <summary class="lavaggio-faq-question">
                        <span>{{ $translation->question }}</span>
                        <span class="lavaggio-faq-toggle" aria-hidden="true"></span>
                    </summary>
                    <div class="content-richtext lavaggio-faq-answer">
                        {!! $translation->answer_html ?: '<p>—</p>' !!}
                    </div>
                </details>
            @endif
        @empty
            <p class="lavaggio-faq-empty">{{ __('ui.faq.empty') }}</p>
        @endforelse
    </section>

    @if ($faqPageTranslation?->body_html)
        <div class="content-richtext lavaggio-faq-help">{!! $faqPageTranslation->body_html !!}</div>
    @endif
</section>
