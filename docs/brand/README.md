# OptiEntry brand assets

`originals/` holds the files supplied by the owner (logo with ledger emblem, navy ledger icon with teal check). They are opaque
(white background). The application uses derived files in `frontend/public/`:

| File | Use |
|---|---|
| `brand/optientry-logo-{400,800,1200,1878}.png` | Sidebar and sign-in logo, served with `srcset` (widths are the real pixel widths; 1878 is the original size). Transparent background. |
| `brand/optientry-icon-{32,48,64,128,192,256,512}.png` | Application icon on a white rounded tile (favicon sizes, web manifest). |
| `favicon.ico` (16/32/48), `apple-touch-icon.png` (180) | Browser tab and home-screen icons. |

Derivation: the near-white background was removed with a flood fill from the outside plus un-mixing of the anti-aliased edge, then each
size was produced with a high-quality (Lanczos) downscale from the full-size master. The book pages of the emblem touch the outside
white, so they become transparent; that is why the logo container is white and the icons sit on a white tile.

SVG: tracing the PNGs into SVG (vtracer, potracer) was tried and rejected because curves came out lumpy. Replace the PNG ladder by a
designer-supplied SVG when one exists; the `<img>` needs no other change.
