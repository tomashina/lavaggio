@php
    $withdrawalContact = app(\App\Services\Front\StoreSettingsService::class)->footer();
    $withdrawalEmail = trim((string) ($withdrawalContact['email_support'] ?? '')) ?: 'info@lavaggio.hr';
@endphp
<h2 class="text-base font-bold text-slate-900">Raskid u roku od 14 dana</h2>
<div class="mt-3 space-y-3 text-sm leading-6 text-slate-700">
    <p>Ugovor sklopljen putem webshopa možete raskinuti bez navođenja razloga u roku od 14 dana od primitka robe. Ako se jedna narudžba isporučuje odvojeno, rok teče od primitka posljednjeg komada ili pošiljke.</p>
    <p>Ispunite obrazac, pregledajte podatke i odaberite „Potvrditi raskid ugovora”. Možete nam poslati i vlastitu nedvosmislenu izjavu e-mailom na <a class="underline" href="mailto:{{ $withdrawalEmail }}">{{ $withdrawalEmail }}</a> ili poštom na dolje navedenu adresu. Dovoljno je poslati izjavu prije isteka roka.</p>
    <h3 class="pt-2 font-bold text-slate-900">Povrat robe i novca</h3>
    <p>Robu pošaljite ili predajte na navedenu adresu bez odgađanja, najkasnije 14 dana nakon slanja izjave. Izravne troškove povrata robe snosite sami.</p>
    <p>Uplaćeni iznos, uključujući trošak najjeftinije ponuđene standardne dostave, vraćamo bez odgađanja, najkasnije 14 dana od primitka izjave. Povrat možemo zadržati dok ne primimo robu ili dokaz da ste je poslali, ovisno o tome što nastupi prije.</p>
    <p>Novac vraćamo istim sredstvom plaćanja, osim ako izričito pristanete na drugi način, bez dodatnog troška za vas. Odgovarate samo za umanjenje vrijednosti zbog rukovanja iznad onoga potrebnog za provjeru prirode, obilježja i funkcionalnosti robe.</p>
    <p>Pravo raskida ima zakonske iznimke, primjerice za robu izrađenu po vašoj specifikaciji, lako pokvarljivu robu te zapečaćenu robu koja zbog zdravstvenih ili higijenskih razloga nije pogodna za povrat ako je otpečaćena nakon dostave.</p>
    <h3 class="pt-2 font-bold text-slate-900">Neispravan proizvod ili prigovor?</h3>
    <p>Reklamaciju i pisani prigovor pošaljite na <a class="underline" href="mailto:{{ $withdrawalEmail }}">{{ $withdrawalEmail }}</a> ili poštom. Na pisani prigovor odgovaramo u roku od 15 dana od primitka. Zakonska prava zbog nedostataka ostvarujete neovisno o roku od 14 dana za raskid.</p>
</div>
@if (filled($withdrawalSettings['return_address'] ?? ''))
    <div class="mt-5 border-t border-slate-200 pt-4">
        <h3 class="text-sm font-bold text-slate-900">Adresa za izjavu i povrat robe</h3>
        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $withdrawalSettings['return_address'] }}</p>
    </div>
@endif
