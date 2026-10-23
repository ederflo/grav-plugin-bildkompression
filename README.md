# Bildkompression: shrink uploaded images in Grav Admin 2

Bildkompression (German for *image compression*) shrinks every image right after it is
uploaded through Grav Admin 2. Phone photos arrive with 10 to 20 MB and more than
4000 px. Grav's image processing scales them down when a page is served, but the
original stays on the server in full size, and lightboxes or "open image" links still
deliver it. This plugin replaces the upload in place with a sensible size.

- Longest side reduced to `max_kante` (default 2560 px), aspect ratio kept
- JPEG and WebP re-encoded with configurable quality, PNG lossless with transparency
- EXIF data is removed, including the **GPS location** of phone photos; the orientation
  is applied first, so portrait photos stay upright
- The file is only replaced if it had to be resized or the result is smaller, so an
  already optimised image stays as it is
- Images above 120 megapixels are left alone (protection against decompression bombs)
- Anything that cannot be processed (animated WebP, broken files) simply stays original;
  the Grav log notes what happened

In production on a Grav 2 site: 447 uploaded phone photos, median 12.9 MB before and 0.7 MB after
(0.2 to 1.3 MB). Together 5.9 GB became 290 MB.

## Requirements

- Grav 2.x with Admin 2 and the API plugin. The plugin listens to the API's
  `onApiMediaUploaded` event, so it does **not** work with the classic Admin of Grav 1.7.
- PHP GD with JPEG, PNG and WebP support. The `exif` extension is recommended; without
  it the orientation of rotated photos cannot be applied.

Developed and tested on Grav 2.2.12, Admin 2 2.1.40, API 1.0.57, PHP 8.4.

## Installation

Download the latest release, unpack it to `user/plugins/bildkompression` and clear the
cache (`bin/grav clearcache`). Uploads to pages and to `user://media` are covered.
Existing images are not touched; only new uploads are shrunk.

## Configuration

Copy `bildkompression.yaml` to `user/config/plugins/bildkompression.yaml`, or use the
plugin settings in the admin panel:

| Key | Default | Meaning |
|---|---|---|
| `max_kante` | `2560` | Longest side in pixels (800 to 8000). |
| `jpeg_qualitaet` | `82` | JPEG quality (50 to 95). 82 is hard to tell apart from the original. |
| `webp_qualitaet` | `80` | WebP quality (50 to 95). |

## Deutsch in Kürze

Verkleinert Bilder gleich nach dem Upload im Admin 2 auf höchstens 2560 px, entfernt die
EXIF-Daten samt GPS-Standort und behält die Ausrichtung. Braucht Grav 2 mit Admin 2 und
API-Plugin sowie PHP GD. Installation: nach `user/plugins/bildkompression` entpacken,
Cache leeren.

## License

MIT, see [LICENSE](LICENSE).
