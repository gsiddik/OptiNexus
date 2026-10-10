# OptiNexus brand assets

`originals/` holds the files supplied by the owner: the logo (delivered as a JPEG, `OptiNexus Logo 1.jpg`, 2816 x 1536) and the emblem
app icon (`OptiNexus Emblem App Icon-5.png`, 1254 x 1254). The console uses derived files in `frontend/public/`:

| File | Use |
|---|---|
| `brand/optinexus-logo-{320,640,960,1207}.png` | Sidebar and sign-in logo, served with `srcset` (widths are real pixel widths; 1207 is the artwork at its original resolution, never upscaled). Transparent background. |
| `brand/optinexus-icon-{32,48,64,128,192,256,512}.png` | Application icon on a white rounded tile (favicon sizes, web manifest). |
| `favicon.ico` (16/32/48), `apple-touch-icon.png` (180) | Browser tab and home-screen icons. |

Derivation: the near-white background was removed with a flood fill from the outside plus un-mixing of the anti-aliased edge (which also
removes the JPEG halo), the artwork was cropped to its bounding box, and every size was produced with a Lanczos downscale from that
full-size master. The logo is shown in a white container because it was designed for light surfaces.

SVG: tracing the raster into SVG was tried and rejected (lumpy curves). Replace the PNG ladder by a designer-supplied SVG when one
exists; `frontend/src/components/BrandLogo.tsx` needs no other change.
