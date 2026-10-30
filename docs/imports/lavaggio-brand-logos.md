# Lavaggio brand logos

The 19 active Lavaggio manufacturers have locally stored official brand artwork in
`public/assets/lavaggio/brands/`. Each displayed asset is a 600 × 260 WebP with a
white background. The artwork was proportionally resized and its surrounding
whitespace trimmed; no logos were redrawn or replaced with text approximations.

Source pages, original asset URLs, retrieval dates and checksums are recorded in
`database/data/lavaggio-brand-logos.json`. Sources are the brands' own websites or
their owners' websites (Henkel, Unilever and Saponia). Logos contain no retailer
branding.

Import the bundled artwork into the standard manufacturer media collection with:

```sh
php artisan db:seed --class=LavaggioBrandLogoSeeder --force
```

The seeder resolves manufacturers by their existing `lavaggio-*` codes and adds
the assets to `manufacturer_logo`. Repeating it leaves matching media unchanged.
An image later uploaded manually through the manufacturer editor is preserved.
The ordinary `ManufacturerLogo::resolve()` path therefore supplies the artwork
to manufacturer widgets and directories, and the logos remain editable in the
existing administration screen.
