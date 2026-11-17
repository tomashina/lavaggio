# Obavijesti o narudžbama

Promjena statusa u detalju narudžbe (uključujući brze akcije) automatski šalje kupcu e-mail nakon uspješnog spremanja transakcije. Bilješka bez promjene statusa ne šalje e-mail; interne bilješke nikad nisu dio poruke.

Nakon uspješnog kreiranja BOX NOW ili GLS pošiljke šalje se broj za praćenje i poveznica dostavne službe. GLS poveznica otvara službenu stranicu za unos broja paketa. Isti broj paketa ne pokreće novu tracking obavijest. Ispis obične adresne naljepnice bez API broja paketa ne šalje tracking poruku. Naknadne poruke o statusu također uključuju postojeći tracking broj.

BOX NOW webhook i sinkronizacija šalju obavijest kada se stvarno promijeni status narudžbe. Ponovljeni i zastarjeli događaji ne šalju dodatne poruke; uvoz više starih događaja šalje samo konačni novi status. Kreiranje pošiljke šalje jednu tracking obavijest, bez dodatne poruke za početni status „Pripremljeno za dostavu”.

U adminu, na detalju narudžbe, **Povijest e-mail obavijesti** prikazuje primatelja, predmet, sadržaj statusne obavijesti, tracking broj, vrijeme i sve pokušaje slanja. „Poslano” znači prihvaćeno od mail transporta, ne potvrdu isporuke u inbox. Isključeno slanje, nevaljana adresa i transport `log` bilježe se kao „Nije poslano”. Greška slanja ne poništava promjenu statusa ili kreiranu pošiljku.

Administrator s pravom uređivanja te narudžbe može ponoviti neuspjelo ili preskočeno slanje, odnosno poslati obavijest koja čeka slanje. Ponovni pokušaj šalje spremljeni sadržaj na izvornu adresu. Uspješno poslane poruke ne šalju se ponovno. Ako proces prestane raditi tijekom slanja, zapis ostaje „Slanje u tijeku”; prije ručne intervencije treba provjeriti SMTP zapis zbog mogućnosti da je poruka već prihvaćena.

Slanje koristi postojeće postavke e-maila trgovine i ne zahtijeva queue worker. Nova povijest odnosi se na obavijesti nastale nakon ove nadogradnje; stare potvrde narudžbi ne rekonstruiraju se.

## Primjena

Uz nove PHP i Blade datoteke potrebno je primijeniti migraciju:

```sh
php artisan migrate --path=database/migrations/2026_11_17_140000_create_order_notifications.php --force
```

Migracija je uključena i u popis `LavaggioDeployMigrations`. Ne mijenja postojeće narudžbe i ne šalje obavijesti za stare događaje.
