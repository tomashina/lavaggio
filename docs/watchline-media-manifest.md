# Watchline — prijenos samo potrebnih slika

`watchline:media-manifest` čita novi shop i izrađuje popis **referenciranih datoteka**, bez preuzimanja, kopiranja, brisanja slika ili izmjene baze. Ne treba prenijeti cijeli stari `image` direktorij niti cijeli `image/cache`.

## Izrada popisa

U korijenu novog Laravel projekta:

```sh
php artisan watchline:media-manifest --json
```

Izlaz navodi novu privatnu mapu `.local/watchline-media/<vrijeme-oznaka>/`. Ta je mapa izvan Gita; ne stavljati je u javni web direktorij. `manifest.json` i popisi imaju prava `0600`, nova izlazna mapa `0700`. Postojeći izlaz nije moguće prepisati. Izbor drugog izlaza dopušten je samo unutar `.local/watchline-media/`.

Obuhvaćeni su svi zadržani artikli, uključujući neaktivne, glavne slike i galerije, kategorije i brandovi, sve jezične verzije opisa, blogovi, stranice, FAQ, javne reference i paketi, sadržajni blokovi/banneri, efektivne postavke Watchline widgeta te javne postavke slika i logotipa. Sadržaji se pregledavaju i za slike u HTML-u, `srcset`, lazy atributima i CSS `url()`. Ne čitaju se kupci, narudžbe, privatni prilozi ni arhivirane izvorne tablice.

| Datoteka | Namjena |
| --- | --- |
| `manifest.json` | Putanje, javni konteksti korištenja, broj referenci, rezultat provjere datoteke, izuzete/neispravne reference i cache preslikavanja. |
| `opencart-originals.files0` | NUL-odvojene putanje samo potrebnih originala, relativne prema starom `image/`. |
| `native-public.files0` | Zaseban popis slika novog shopa, relativan prema njegovom `storage/app/public/`; ne prema starom OpenCartu. |

Putanje čuvaju velika/mala slova i razmake. Popis ne sadrži direktorije, wildcard uzorke ni `cache/` datoteke. Nevažeće, dvostruko kodirane ili izlazeće putanje i URL-ovi s prijavnim podacima odbijaju se. Nepovezane vanjske domene i ugrađene data slike ne prenose se. Za odbačene reference sprema se razlog i otisak, bez cijelog URL-a, tokena ili HTML-a.

`git_public_assets` su javne slike iz `assets/`, `front-theme/` i `build/`, koje dolaze s Git objavom. Native Media Library slike obuhvaćaju original i registrirane generirane konverzije/responsive datoteke; nepovezani ostaci u direktoriju nisu automatski uključeni. Drugi diskovi označavaju se za pregled bez kontaktiranja pružatelja.

## Provjera prema stvarnom starom image direktoriju

Bez `--source-root` putanje su potvrđene kao reference iz nove baze, ali njihovo postojanje i veličina na starom serveru nisu provjereni. Nakon što je popis dostupan u okruženju koje može samo čitati stari direktorij:

```sh
php artisan watchline:media-manifest \
  --source-root=/home/watchline/public_html/image \
  --json
```

To provjerava **samo odabrane putanje**, uključujući točna velika/mala slova, nepraznu datoteku i zadržavanje unutar izvora. Ne obilazi se cijeli stari direktorij. Nedostajuće, prazne i symlink datoteke ostaju navedene u JSON-u, ali ne ulaze u provjereni `opencart-originals.files0`. Takve nalaze treba riješiti prije prijenosa. Provjera ne potvrđuje vizualni sadržaj niti potpunost nekog budućeg, još neuvezenog SQL snimka.

Nakon pregleda može se pripremiti rsync **suha proba** s točno tim popisom i prethodno potvrđenim odredištem:

```sh
rsync -anR --no-owner --no-group --no-links --safe-links \
  --chmod=D755,F644 --from0 \
  --files-from=/PRIVATE/MANIFEST/opencart-originals.files0 \
  -- /home/watchline/public_html/image/ /home/watchline/new.watchline.hr/image/
```

Prije stvarnog kopiranja odredišni `image` korijen mora biti stvarni direktorij, bez symlinka ili aliasa prema izvornom OpenCartu. Nakon stvaranja izričito postaviti `chmod 755 /home/watchline/new.watchline.hr/image` i potvrditi dopuštenja: privatni shell `umask 077` može ostaviti taj korijen na `0700`, a rsync `--chmod` za navedene datoteke ne ispravlja nužno već postojeći vršni direktorij. Poddirektoriji trebaju biti `0755`, javne datoteke `0644`; privatni runtime i baza ostaju zatvoreni.

`/PRIVATE/MANIFEST` treba zamijeniti stvarnom privatnom izlaznom mapom. Ovaj primjer ima `-n` i ništa ne kopira. Stvarni prijenos je zaseban pregledan korak; naredba manifesta ga ne pokreće. Ne dodavati `--delete`. Native popis koristi zaseban korijen `storage/app/public/`. Javni `storage` link i Git resursi pripremaju se pri objavi aplikacije.

## Stare cache poveznice

Originalni OpenCart `catalog/model/tool/image.php` gradi cache naziv kao `cache/<original-bez-ekstenzije>-<širina>x<visina>.<ista-ekstenzija>`. Alat poništava **jedan završni** dimenzijski nastavak: `cache/catalog/Sat-200x200-800x800.jpg` preslikava se na `catalog/Sat-200x200.jpg`.

Takav original ulazi u odabir; `cache_aliases` bilježi staru cache putanju i zahtjev za usklađivanje URL-a novog shopa. Sam prijenos originala **ne popravlja** HTML koji još upućuje na `/image/cache/...`. Potrebna je odvojena provjera/normalizacija takvih poveznica ili uski dogovoreni kompatibilni korak. Alat ih ne mijenja i ne prenosi cijeli cache. Neobičan naziv bez prepoznatljivog dimenzijskog nastavka odbija se radi ručnog pregleda.

Popis ponovno izraditi nakon svježeg uvoza artikala, uređivanja widgeta ili promjene slika; stari manifest nije dokaz da novododani sadržaj ima sve potrebne datoteke.
