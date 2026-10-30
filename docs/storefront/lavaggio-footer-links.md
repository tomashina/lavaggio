# Lavaggio footer links

Run this dedicated seeder after the original Lavaggio content has been imported:

```sh
php artisan db:seed --class=LavaggioFooterLinksSeeder
```

The seeder only runs in the local Lavaggio SQLite preview. Before writing to the persistent local database, it creates a consistent private backup under `storage/app/private/lavaggio-demo-backups/`.

It stores the ten footer links in the system setting `lavaggio_footer_legal_links`, an ordered array of objects with `label` and `url` fields. It leaves the existing three footer columns unchanged.

In **Settings → Store → Branding & Footer**, the Lavaggio section edits the heading and ordered list of links. Each line contains `Label|URL`; adding, removing or moving lines changes the footer. LinkedIn and the other social profile URLs and visibility are edited in the same tab. Destination page titles and content are edited through **Content → Pages**.

Social icons appear once in the row above the final copyright bar, with cookie preferences at the left edge and the back-to-top button at the right edge. Both controls remain available on mobile and use their existing behavior without floating over page content.

Six dedicated webshop information pages initially contain only “Sadržaj ove stranice je u pripremi.” They are editable through the existing information page administration. These placeholders do not define payment, delivery, returns, purchase terms or accessibility compliance. Supply the actual approved content before publishing a production shop.

The privacy, cookies and complaint links reuse the imported `lavaggio-modx-84` page and add three anchors without replacing its wording. The employment link reuses `lavaggio-modx-81`. Those two imported pages must already be active and published.

Repeated runs preserve edited page text, titles, slugs and visibility, as well as any existing value of `lavaggio_footer_legal_links`. This seeder is intentionally separate from the general application seeders; invoke it explicitly when configuring a fresh local Lavaggio preview.
