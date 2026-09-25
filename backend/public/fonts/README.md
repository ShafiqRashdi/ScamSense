# Self-hosted fonts

**Instrument Sans**, by Rodrigo Fuenzalida and Jordan Egstad.
Licensed under the [SIL Open Font License 1.1](https://openfontlicense.org/),
which permits bundling and redistribution with a work of this kind.

Source: <https://fonts.google.com/specimen/Instrument+Sans>

## Why these files are committed rather than linked

The content security policy sets `font-src 'self'` (see
`app/Http/Middleware/SecurityHeaders.php`). Linking Google Fonts would require
loosening that to allow `fonts.googleapis.com` and `fonts.gstatic.com`, which
would contradict the claim in the security write-up that the policy can be
strict *because* the interface loads nothing third-party.

There is a privacy argument too. A webfont request discloses the visitor's IP
address and user agent to the font host on every page load. On a site whose
entire premise is that the visitor should be careful about who receives their
data, importing a third-party request to render the word "scam" is not a good
look, and it is not necessary.

## What is here

| File | Subset | Size |
|---|---|---:|
| `instrument-sans-latin.woff2` | Latin | 30 KB |
| `instrument-sans-latin-ext.woff2` | Latin Extended | 11 KB |

Both are **variable** files covering weights 400–700, which is why there is one
file per subset rather than one per weight. The `@font-face` rules in
`resources/css/app.scss` carry the matching `unicode-range` declarations, so a
browser downloads the extended subset only if a page actually needs it — which
for Malay and English is rare.

## Updating

Fetch the CSS Google serves for the family, take the `woff2` URLs it lists, and
replace both files. Keep the `unicode-range` values in `app.scss` in step with
whatever the new CSS declares: a mismatch does not error, it silently renders
the wrong glyphs from a fallback face.
