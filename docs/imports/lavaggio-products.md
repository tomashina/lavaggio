# Lavaggio local product catalogue

Imported on 28 October 2026 into the isolated local Lavaggio SQLite database. The initial 20 products were expanded by 50 laundry products and 15 further accessories, bringing the catalogue to 85.

- 85 real products from 19 national brands: Ariel, Persil, Perwoll, Frosch, Vanish, Dr. Beckmann, Lenor, Sanytol, Calgon, Vileda, Coccolino, Faks, Sil, Silan, Sagrotan, WENKO, Bürstenmann, Leifheit and HEITMANN. No dm private labels or retailer overlays.
- The additional batch contains 18 detergents, 16 stain treatment products and 16 care/hygiene/washer maintenance products. There are two verified professional products in total.
- 85 main photographs and 41 additional packshots/label photographs are stored locally as opaque WebP files on white. All main source originals measure 1500–4700 pixels on the longer side. Additional views measure 1000–3415 pixels; four WENKO supplementary views are limited to 1000 pixels by the manufacturer. Source resolution is retained for zoom, with responsive card/detail conversions. There are galleries on 36 products. Two old Persil supplementary images were removed because they showed a different front-label design or a marketing collage.
- Curated product snapshot: `database/data/lavaggio-products.json`. Image dimensions and provenance: `database/data/lavaggio-image-audit.json`. The additional 50-product batch is retained locally with the research materials.
- Matching EANs, packaging and source retail prices were checked. Croatian product descriptions were written, and foreign-language legal declarations were translated with the original wording and source provenance retained in the snapshot and product import metadata.
- The complete catalogue has 85 distinct EANs and 85 distinct SKUs. Later import batches allocate new sequential SKUs without changing existing product identifiers.

## Data limitations and source review

Country of origin is not confirmed for ten additional products: Sil gel 4015000971881; Frosch baby Flecken-Zwerg 4001499969430; Dr. Beckmann 4008455108513, 4008455004099, 4008455004266, 4008455004068, 4008455038919, 4008455114316, 4008455109916 and 4008455087818. These fields remain empty and are explicitly marked as unpublished. A manufacturer address is not treated as proof of manufacturing country.

Seven physical accessories and the reusable Dr. Beckmann colour catcher also have no confirmed manufacturing country. No safety information was published for seven physical accessories, so those fields remain empty rather than asserting that no precautions are necessary. All accessories have source-confirmed basic materials. The total catalogue has 19 unconfirmed manufacturing countries.

The original Vileda roller 4023103126251 also has an unconfirmed manufacturing country because sources disagree. No product-specific hazard notes were published in the selected source. Those two fields remain empty; materials are included under ingredients. The storefront states when information is unavailable.

Source catalogues occasionally combine component hazard classifications from SDS section 16 with finished-product warnings. The two existing Perwoll declarations were corrected using manufacturer SDS section 2 linked from matching-EAN product pages and corroborated by exact-EAN label text. Ariel Ultra Oxi warnings were checked against matching-EAN Slovenian/Hungarian declarations. An unreliable Lenor Japanese Blossom entry was replaced with verified Ruby Jasmin before the final catalogue was accepted. Rejected source wording and correction evidence are retained. Missing concentration symbols and active ingredients were checked against legible packaging photographs or matching-EAN supplementary sources.

## Local examples

Reference retail prices are local seed prices, not an approved Lavaggio price list. Stock quantities and delivery modes are demonstration data, not supplier inventory. Four existing local example promotions apply a 20% discount for seven days; no additional promotions were created for the expansion. Their dates are stored in the application timezone and entered/displayed as Europe/Zagreb in administration. Historical prices from other retailers were not imported as Lavaggio price history. Listing prices/dates start with the actual local import.

No customer reviews or ratings were invented. The existing review submission and moderation flow remains available, with an explicit empty-review state.

The demonstration delivery method costs €4.90 and is free from the configured €50 threshold. The threshold can be changed in Store settings → Pricing; saving also updates the announcement and active delivery methods. Imported prices include VAT and use the local 25% tax rate.

## Re-run

```sh
php artisan migrate --force
php artisan lavaggio:import-products
php artisan lavaggio:import-products --execute
```

The command is restricted to the local Lavaggio database. The expanded batch was re-run successfully with zero additional photographs or duplicate products. Existing stock, listing dates, delivery modes and promotion schedules are preserved. The 20 original products retained their SKUs, prices, inventory and listing information during the expansion.

The optional `--demo-promotions` flag creates local example promotions for every fifth product in the supplied snapshot. It was not used for the additional batch.

Six editable attribute groups power the catalogue filters. The accessory category now contains 16 products in its four subcategories. Leaf navigation entries without children are direct links without a chevron or a duplicate right-hand panel. The four contextual problem links live inside the existing shop navigation on desktop and mobile and use the same filter values. Their category association uses stable category identities rather than translated labels or URLs.

Private SQLite backups were saved under `storage/app/private/backups/products-before-*.sqlite`, including a separate backup immediately before the 50-product expansion.

## Verification

All 85 product detail routes are checked for HTTP 200 with the matching EAN and legal sections. Every product has a local main photograph with all required card/detail conversions, category assignments and filter attributes. The import integration tests cover repeated imports, later batches, source metadata, unchanged inventory and working problem filter links.

## Example bundles

Twelve editable local example bundles are available at `/bundle-artikli`, each with exactly two different products (one unit of each), using 24 distinct products. Every bundle has an example 10% discount for the default retail customer group. All selected products were locally in stock at creation. The bundle amount shown on the storefront matches the amount calculated by the cart, including VAT; browsing and adding a bundle do not consume stock.

Definitions are in `database/data/lavaggio-example-bundles.json`. Run `php artisan db:seed --class=LavaggioExampleBundlesSeeder --force` only in the guarded local Lavaggio database. Re-running it creates no duplicate bundles and preserves later administration edits. The existing “Kupi u kompletu i uštedi” promotion remains and uses copy about laundry care.

## Product readability

Lavaggio product detail typography is larger and darker, with distinct sizes for titles, prices and supporting details. The three optional “Osnovne informacije”, “Načini dostave” and “Načini plaćanja” accordions are omitted. Product specifications, identifiers, delivery notices, documents and all five legal information panels remain.

Product source images before WebP normalization were archived privately under `storage/app/private/backups/product-media-before-webp-*/`, with a manifest. The enlarged gallery uses a white backdrop with dark controls and the full-resolution local WebP original. New imports use the same white WebP normalization pipeline.

CMYK JPEG sources are converted to sRGB before white compositing and WebP encoding. The three CMYK accessory photographs were regenerated with correct colours; all original product photographs were surveyed for unexpected dark backgrounds. Regression checks cover both transparent PNG and CMYK JPEG input.
