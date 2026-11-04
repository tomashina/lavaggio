<div class="space-y-4 rounded-xl border border-slate-200 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Promo slajdovi</h3>
            <p class="mt-1 text-xs text-slate-500">Fotografija s odvojenim tekstom i gumbom bolje se prilagođava mobitelu. Možete odabrati i prikaz samo fotografije.</p>
        </div>
        @if (count($form['watchline']['slides'] ?? []) < \App\Support\LavaggioCampaignSlides::LIMIT)
            <button type="button" wire:click="addLavaggioSlide" class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-semibold text-cyan-800">Dodaj slajd</button>
        @endif
    </div>
    @if (($form['watchline']['slides'] ?? []) === [])
        <p class="text-sm text-slate-600">Trenutno se prikazuje glavni banner iz polja iznad. Dodavanjem slajda postojeći banner postaje prvi slajd i njegov sadržaj ostaje sačuvan.</p>
    @endif
    @foreach (($form['watchline']['slides'] ?? []) as $slideIndex => $slide)
        <fieldset wire:key="lavaggio-slide-{{ $slideIndex }}" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <legend class="px-1 text-sm font-semibold text-slate-800">Slajd {{ $slideIndex + 1 }}</legend>
            <div class="flex flex-wrap items-center gap-3">
                <label class="mr-auto inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.watchline.slides.{{ $slideIndex }}.enabled" class="rounded border-slate-300"> Prikaži slajd</label>
                @if (! $loop->first)<button type="button" wire:click="moveLavaggioSlide({{ $slideIndex }}, 'up')" class="text-sm font-semibold text-cyan-800">Gore</button>@endif
                @if (! $loop->last)<button type="button" wire:click="moveLavaggioSlide({{ $slideIndex }}, 'down')" class="text-sm font-semibold text-cyan-800">Dolje</button>@endif
                @if (count($form['watchline']['slides']) > 1)<button type="button" wire:click="removeLavaggioSlide({{ $slideIndex }})" class="text-sm font-semibold text-rose-700">Ukloni</button>@endif
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Prikaz
                    <select wire:model.live="form.watchline.slides.{{ $slideIndex }}.display" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="text_image">Fotografija, tekst i gumb</option><option value="image">Samo fotografija</option></select>
                </label>
                @foreach (['title' => 'Naslov / naziv promocije', 'eyebrow' => 'Mali naslov', 'cta_label' => 'Tekst gumba', 'url' => 'Poveznica'] as $slideField => $slideLabel)
                    <label class="text-xs font-semibold text-slate-600">{{ $slideLabel }}<input type="text" wire:model="form.watchline.slides.{{ $slideIndex }}.{{ $slideField }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    @error('form.watchline.slides.'.$slideIndex.'.'.$slideField)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @endforeach
                <label class="text-xs font-semibold text-slate-600 md:col-span-2">Kratak opis<textarea rows="2" wire:model="form.watchline.slides.{{ $slideIndex }}.subtitle" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label>
                <div class="md:col-span-2">
                    <label for="lavaggio-slide-image-{{ $slideIndex }}" class="text-xs font-semibold text-slate-600">Fotografija</label>
                    <input id="lavaggio-slide-image-{{ $slideIndex }}" type="file" accept="image/jpeg,image/png,image/webp" wire:model="lavaggioSlideUploads.{{ $slideIndex }}" class="mt-1 block w-full text-sm">
                    <p class="mt-1 text-xs text-slate-500">JPG, PNG ili WebP, do 5 MB. Za prikaz samo fotografije koristite široku sliku bez sitnog teksta.</p>
                    <p wire:loading wire:target="lavaggioSlideUploads.{{ $slideIndex }}" class="text-xs text-cyan-700">Slika se učitava…</p>
                    @error('lavaggioSlideUploads.'.$slideIndex)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @php
                        $pendingSlideImage = $lavaggioSlideUploads[$slideIndex] ?? null;
                        $slidePreview = $pendingSlideImage instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && ! $errors->has('lavaggioSlideUploads.'.$slideIndex)
                            ? $pendingSlideImage->temporaryUrl() : \App\Support\WatchlineWidgetSettings::safeUrl($slide['image'] ?? '', true);
                    @endphp
                    @if ($slidePreview !== '')<img src="{{ $slidePreview }}" alt="Pregled fotografije slajda" width="240" height="140" class="mt-2 h-32 max-w-full rounded-lg bg-white object-contain">@endif
                    <details class="mt-2 text-xs text-slate-500"><summary class="cursor-pointer">Ili poveznica postojeće slike</summary><input type="text" wire:model.live.debounce.500ms="form.watchline.slides.{{ $slideIndex }}.image" aria-label="Poveznica fotografije slajda {{ $slideIndex + 1 }}" class="mt-2 w-full rounded-lg border-slate-300 text-sm"></details>
                    @error('form.watchline.slides.'.$slideIndex.'.image')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <label class="text-xs font-semibold text-slate-600 md:col-span-2">Opis fotografije<input type="text" wire:model="form.watchline.slides.{{ $slideIndex }}.image_alt" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            </div>
        </fieldset>
    @endforeach
</div>
