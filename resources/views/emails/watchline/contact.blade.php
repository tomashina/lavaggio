@component('emails.watchline.layout', ['title' => $isReturn ? 'Novi zahtjev za povrat' : 'Nova poruka iz trgovine'])
    <p class="eyebrow">Watchline · Obavijest za trgovinu</p>
    <h1>{{ $isReturn ? 'Novi zahtjev za povrat.' : 'Nova poruka kupca.' }}</h1>
    <table class="details" role="presentation">
        <tr><th>Ime i prezime</th><td>{{ $contact->name }}</td></tr>
        <tr><th>E-mail</th><td>{{ $contact->email }}</td></tr>
        @if ($contact->phone)<tr><th>Telefon</th><td>{{ $contact->phone }}</td></tr>@endif
        <tr><th>Predmet</th><td>{{ $contact->subject }}</td></tr>
    </table>
    <div class="panel pre-line">{{ $contact->message }}</div>
    <p class="muted">Na upit možete odgovoriti izravnim odgovorom na ovu poruku.</p>
@endcomponent
