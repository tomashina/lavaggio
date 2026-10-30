# Homepage product group

Created 28 October 2026 using the built-in ImageGen tool in compositing mode. This is a homepage marketing montage; original catalogue product photographs are unchanged.

Final asset: `public/assets/lavaggio/home/lavaggio-product-group-v1.webp`.

The generated PNG was encoded as WebP at quality 92 with alpha quality 100, without compositing onto a background. Transparent pixels remain transparent. The intended display background is the homepage card's pale lavender.

## Exact catalogue references

All five local reference photographs were visually inspected before the image generation call.

| Product | EAN | Local reference |
| --- | --- | --- |
| Ariel Sensitive liquid detergent 2.7 l | 8700216691550 | `storage/app/public/795/8700216691550-0.webp` |
| Frosch baby liquid detergent 1.5 l | 4009175968616 | `storage/app/public/796/4009175968616-0.webp` |
| Vanish Oxi Action Pink gel 1 l | 5999109563200 | `storage/app/public/828/5999109563200-0.webp` |
| Lenor Spring Awakening softener 1.491 l | 8700216724074 | `storage/app/public/804/8700216724074-0.webp` |
| Dr. Beckmann PreWash stain remover 250 ml | 4008455342313 | `storage/app/public/802/4008455342313-0.webp` |

Generated original: `/Users/tomek/.codex/generated_images/01a1828f-3732-7553-b1d1-cd4904f6094c/exec-04bfe4f7-68be-4f8f-a969-da5b057a2dec.png`.

## Final generation prompt

```text
Use case: compositing.
Asset type: transparent product group for the Lavaggio laundry webshop homepage hero, displayed about 450px wide on a pale lavender card.
Primary request: create one polished, photorealistic studio montage of EXACTLY the five real laundry products supplied in the five reference images. These are supporting compositing inputs, not inspiration for redesign.
Input images: 1 Ariel Sensitive liquid laundry detergent 2.7L white handled bottle; 2 Frosch baby laundry detergent 1.5L pale green clear bottle; 3 Vanish Oxi Action Pink gel 1L magenta bottle; 4 Lenor Spring Awakening 1.491L blue bottle; 5 Dr.Beckmann PreWash stain remover 250ml white bottle.
Composition: compact balanced five-product group, landscape 5:4 image, all bottles standing upright on the same invisible horizontal plane, front labels face camera, gentle overlap only at bottle edges, every brand logo visible. Ariel tallest at left rear; Frosch near center rear; Lenor right rear; Vanish slightly forward left-center; Dr.Beckmann smallest forward right-center. Preserve realistic relative package sizes. Group occupies most of the canvas with only a modest transparent margin, no empty copy area. All caps and feet visible, no cropping.
Lighting: soft bright professional studio light, clean natural material highlights, subtle soft contact shadows beneath bottles only.
Critical invariants: composite these exact packages faithfully without inventing products, rewriting labels, replacing typography, changing logos, changing cap shapes or colors, or changing bottle silhouettes. Preserve recognizable original front label artwork. Do not add anything else.
Background: genuinely transparent alpha background across all empty space, including between bottle silhouettes and in handle holes. No white rectangle, no black background, no checkerboard, no pedestal or surface, no gradient, no grid, no decorative objects, no towels, no added text, no watermark, no retailer logos.
```
