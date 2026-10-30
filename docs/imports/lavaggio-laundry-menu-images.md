# Fotografije u izborniku Praonica

Dodano 28. listopada 2026. Šest fotografija preuzeto je s postojećih Lavaggio stranica u izvornoj rezoluciji. Fotografije prikazuju stvarni interijer, pripremljeno rublje, dostavu, ozonski uređaj i tim praonice. Izvori, izrezi, kontrolni sažeci i veličine datoteka nalaze se u `database/data/lavaggio-laundry-menu-images.json`.

Sve pripremljene datoteke imaju omjer 4:3, dimenzije 1000 × 750 px i format WebP. Izvorne fotografije nisu generirane ni sadržajno mijenjane. Izvor za ozonski uređaj ima 980 px širine; njegov završni izrez blago je povećan na zajedničku dimenziju, dok je prikaz u izborniku znatno manji.

| Stavka izbornika | Odabrana fotografija |
| --- | --- |
| Samoposlužna praonica rublja | Interijer s perilicama i prostorom za pripremu |
| Uslužna praonica rublja | Oprano i zapakirano rublje |
| Prikup i dostava rublja | Pripremljeno rublje u dostavnom vozilu |
| Ozonsko pranje rublja | Ozonski uređaj u praonici |
| Cjenik | Prostor za pripremu rublja s pregledom programa |
| O praonici | Lavaggio tim ispred ulaza |

Fotografije su spremljene u `public/assets/lavaggio/navigation/`. Povezane su s pojedinim stavkama preko postojećeg polja `desktop_promo_image_path`, a admin ih može zamijeniti ili ukloniti u **Sadržaj → Navigacija → Podstavke izbornika → Fotografija u izborniku**.

`LavaggioLaundryMenuImagesSeeder` dopunjava samo prazna polja fotografije na šest prepoznatih stranica. Čuva postojeće fotografije, nazive, redoslijed, poveznice i cijelu strukturu podizbornika. Može se samostalno ponovno pokrenuti. Početni `LavaggioContentNavigationSeeder` poziva ga nakon stvaranja navigacije.

Stavke bez podstavki, uključujući **Što peremo u našoj praonici** i **Rezervacije 2027**, zadržavaju izravnu poveznicu u lijevom stupcu bez dodatne ploče.
