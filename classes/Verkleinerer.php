<?php
/**
 * Verkleinert ein Bild an Ort und Stelle (ohne Grav-Abhängigkeit, per CLI testbar).
 *
 * Längste Kante auf max_kante, neu kodiert (JPEG/WebP verlustbehaftet, PNG
 * verlustfrei). Die Datei wird nur ersetzt, wenn sie verkleinert werden musste
 * oder das Ergebnis kleiner ist — ein schon gut komprimiertes Bild bleibt, wie es ist.
 * Beim Neuschreiben fallen alle EXIF-Daten weg, also auch der GPS-Standort.
 */
namespace Grav\Plugin\Bildkompression;

class Verkleinerer
{
    /** Mehr Pixel als das lassen wir in Ruhe (Schutz vor „Pixelbomben“). */
    private const MAX_PIXEL = 120_000_000;

    /**
     * @return array{vorher:int, nachher:int, ersetzt:bool, breite:int, hoehe:int}|null
     *         null = kein unterstütztes Bild, nichts getan
     */
    public static function verkleinern(string $datei, int $maxKante = 2560, int $jpegQualitaet = 82, int $webpQualitaet = 80): ?array
    {
        $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
        if (!in_array($endung, ['jpg', 'jpeg', 'png', 'webp'], true) || !is_file($datei)) {
            return null;
        }
        $info = @getimagesize($datei);
        if (!$info || $info[0] * $info[1] > self::MAX_PIXEL) {
            return null;
        }
        $typ = $info[2];
        $vorher = filesize($datei);

        // Ein 48-MP-Handyfoto braucht im Speicher rund 200 MB
        @ini_set('memory_limit', '1024M');

        $bild = match ($typ) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($datei),
            IMAGETYPE_PNG  => @imagecreatefrompng($datei),
            IMAGETYPE_WEBP => @imagecreatefromwebp($datei), // animiertes WebP liefert false
            default        => false,
        };
        if (!$bild) {
            return null;
        }

        if ($typ === IMAGETYPE_JPEG) {
            $bild = self::drehungAnwenden($bild, $datei);
        }

        $breite = imagesx($bild);
        $hoehe = imagesy($bild);
        $faktor = min(1, $maxKante / max($breite, $hoehe));
        $verkleinert = $faktor < 1;
        if ($verkleinert) {
            $nb = max(1, (int) round($breite * $faktor));
            $nh = max(1, (int) round($hoehe * $faktor));
            $neu = imagecreatetruecolor($nb, $nh);
            if ($typ !== IMAGETYPE_JPEG) {
                imagealphablending($neu, false);
                imagesavealpha($neu, true);
            }
            imagecopyresampled($neu, $bild, 0, 0, 0, 0, $nb, $nh, $breite, $hoehe);
            imagedestroy($bild);
            $bild = $neu;
            [$breite, $hoehe] = [$nb, $nh];
        }

        $tmp = $datei . '.bk-' . getmypid() . '.tmp';
        if ($typ === IMAGETYPE_JPEG) {
            imageinterlace($bild, true); // progressiv: baut sich am Handy schneller auf
        } else {
            imagesavealpha($bild, true);
        }
        $ok = match ($typ) {
            IMAGETYPE_JPEG => imagejpeg($bild, $tmp, $jpegQualitaet),
            IMAGETYPE_PNG  => imagepng($bild, $tmp, 9),
            IMAGETYPE_WEBP => imagewebp($bild, $tmp, $webpQualitaet),
        };
        imagedestroy($bild);
        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }

        clearstatcache(true, $tmp);
        $nachher = filesize($tmp);
        $ersetzt = $verkleinert || $nachher < $vorher;
        if ($ersetzt) {
            @chmod($tmp, fileperms($datei) & 0777);
            rename($tmp, $datei);
        } else {
            unlink($tmp);
            $nachher = $vorher;
        }

        return ['vorher' => $vorher, 'nachher' => $nachher, 'ersetzt' => $ersetzt, 'breite' => $breite, 'hoehe' => $hoehe];
    }

    /** Handyfotos liegen oft quer gespeichert und tragen die Drehung nur als EXIF-Wert. */
    private static function drehungAnwenden(\GdImage $bild, string $datei): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $bild;
        }
        $exif = @exif_read_data($datei);
        $wert = (int) ($exif['Orientation'] ?? 1);

        if (in_array($wert, [2, 7], true)) {
            imageflip($bild, IMG_FLIP_HORIZONTAL);
        } elseif (in_array($wert, [4, 5], true)) {
            imageflip($bild, IMG_FLIP_VERTICAL);
        }
        $winkel = match ($wert) {
            3       => 180,
            5, 6, 7 => -90, // imagerotate dreht gegen den Uhrzeigersinn
            8       => 90,
            default => 0,
        };
        if ($winkel !== 0) {
            $gedreht = imagerotate($bild, $winkel, 0);
            if ($gedreht) {
                imagedestroy($bild);
                $bild = $gedreht;
            }
        }
        return $bild;
    }
}
