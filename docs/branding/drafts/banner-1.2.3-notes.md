# 1.2.3 banner contrast candidate

`banner-1.2.3-blue-candidate.png` is an image-assisted edit of the existing
directory banner. It preserves the layout and wording, adds a pale cool-blue
background, and darkens the tagline. This is a draft, not a production export.

Visual review: title and tagline remain readable; the white logo tile separates
from the blue background; the existing electric-blue accents remain prominent.
However, the generated logo is not pixel-identical to the original SVG and tiny
dark bars appear at the top and bottom edges. Do not deploy this file as-is.

Before adoption, use the original `icon.svg` in the established export pipeline,
remove the edge artifacts, and verify both 1544 x 500 and 772 x 250 exports. Keep
the approved source and current production exports unchanged until that review.
The walkthrough video can be added later when the owner supplies a public URL;
no video URL or unshipped capability has been invented for this listing.

## Finalized local export

The source was edge-trimmed into `../source/banner-1.2.3-blue.png`. The established
export script masks the generated logo and composites the unchanged original
`icon.svg`. Both `.wordpress-org/banner-1544x500.png` and `banner-772x250.png`
were visually inspected: readable text, clean edges and original mark restored.
These are local candidate assets only; no publication was performed.
