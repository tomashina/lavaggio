# Proxima Nova web fonts

Downloaded on 2026-11-02 from the font URLs in the user-provided
[Proxima Nova gist](https://gist.github.com/praveenscience/4063b6db4bb3774d21e2219eeb00ff03).

Source base URL: `https://d25purrcgqtc5w.cloudfront.net/dist/fonts/proximanova/`

| Local files (WOFF2 and WOFF) | CSS weight | Source basename |
| --- | --- | --- |
| `proxima-nova-light.*` | 300 | `302D42_1_0` |
| `proxima-nova-regular.*` | 400 | `302D42_4_0` |
| `proxima-nova-bold.*` | 700 | `302D42_5_0` |

All files use the normal style. CloudFront serves these files with gzip transport
encoding; the local files contain the decoded WOFF2/WOFF bytes, without modifying
the font data. CSS must declare the weights above explicitly, matching the gist:
the supplied font metadata is sanitized and reports weight 400 for every file.

The WOFF character maps were checked and contain all Croatian letters:
`čćđšž ČĆĐŠŽ`.

The source gist does not include a font license file. Existing licensing terms
and any applicable font permissions remain with the font owner.
