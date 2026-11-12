# BOX NOW slanje i praćenje

U **Admin → Narudžbe → detalji narudžbe → BOX NOW pošiljka** odaberite veličinu zapakiranog paketa (mali, srednji ili veliki), kliknite **Pošalji u BOX NOW**, zatim **Preuzmi PDF naljepnicu**. Veličina se šalje kao `items[].compartmentSize` (1, 2 ili 3); obvezna je i pri slanju iz paketomata. Jedna narudžba kreira jedan paket. Ponovni klik vraća postojeću pošiljku. Greška preuzimanja PDF-a ne kreira novi paket.

U postavkama dostave potrebni su službeni HTTPS API URL, OAuth Client ID, Client Secret i Warehouse ID. Paketomat i podaci primatelja uzimaju se iz narudžbe. Za pouzeće se šalje iznos narudžbe; već plaćene narudžbe šalju se kao prepaid. Poziv za izradu pošiljke obavlja se isključivo ručnom akcijom administratora.

Kupac odmah vidi broj paketa, poveznicu za praćenje, status „Pripremljeno za dostavu” i povijest. Nakon događaja prijevoznika slijede „Poslano”, „Spremno za preuzimanje” i „Dostavljeno”. Povrati i poteškoće imaju odvojene statuse. Status dostave ne označava narudžbu plaćenom. Otkazane i refundirane narudžbe zadržavaju poslovni status, a događaji pošiljke i dalje se spremaju.

## Automatska provjera

`php artisan boxnow:sync` provjerava pošiljke i sprema promjene. Laravel raspoređivač poziva naredbu svakih pet minuta. Ako server nema aktivan Laravel scheduler, uključite samo ovu naredbu kroz cron, uz zaključavanje i privatni log. Nemojte uključivati druge integracije kao dio ovog deploya.

Ručna provjera jedne narudžbe: `php artisan boxnow:sync --order=123`. Završene pošiljke provjeravaju se još 14 dana; webhook ostaje dostupan i kasnije. Neuspjeli pokušaji stvaranja provjeravaju se prema istom broju narudžbe. Nakon neodređenog ishoda ili timeouta ne šalje se drugi zahtjev za novi paket. Ako BOX NOW ne vrati postojeću pošiljku, razriješite prvi pokušaj s njihovom podrškom prije ručnog zahvata u zapis.

## Trenutni događaji preko web hooka

U postavkama BOX NOW dostave spremite nasumičan token duljine najmanje 32 znaka. Sučelje prikazuje URL `/api/integrations/boxnow/{shippingMethod}/webhook`. BOX NOW podršci treba dostaviti taj javni HTTPS URL i zaglavlje `Authorization: Bearer [token]`. Token je šifriran u bazi. Endpoint odbija zahtjeve bez odgovarajućeg tokena i zahtijeva podudaranje broja narudžbe i paketa. Webhook ne radi dok ga BOX NOW ne registrira; do tada radi periodička provjera.

Koristi se `data.event` i vrijeme `data.time`; duplikati se zanemaruju, a stariji događaji ne vraćaju status unatrag. Pristup PDF-u imaju samo ovlašteni djelatnici. Kupac vidi samo svoju narudžbu i javne podatke praćenja.

## Deploy

Migracija `2026_11_12_120000_create_box_now_shipments` dodaje dvije prazne tablice; uvrštena je u pregledane Lavaggio migracije. Statusi se dodaju tek pri prvom odgovarajućem događaju. Migracija ne šalje pošiljke niti mijenja postojeće narudžbe. Nakon uobičajenog cPanel deploya provjerite raspoređivač, postavke API-ja i prikaz narudžbe. Testovi koriste lažne API odgovore i ne šalju stvarne pakete.

Dokumentacija prijevoznika: [kreiranje i naljepnice](https://www.boxnow.hr/en/diy/eshops/tailor-made), [popis paketa i događaji](https://boxnow.gr/en/docs/api/partner-api/see-all-parcels), [webhook](https://boxnow.hr/media/PDF/Webhook_responses-v5.pdf).
