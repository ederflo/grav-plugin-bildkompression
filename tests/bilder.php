<?php
/**
 * Testbilder für die Bildkompression, alle mit GD selbst erzeugt: Verlauf mit Mustern
 * (komprimiert ähnlich wie ein Foto), dazu ein EXIF-Block mit Ausrichtung, Kamera und
 * einem erfundenen GPS-Standort.
 */
declare(strict_types=1);

/** Bild mit Verlauf, Streifen und Kreisen; eine rote Ecke oben links zeigt die Ausrichtung. */
function testbild(int $breite, int $hoehe, bool $alpha = false): \GdImage
{
    $b = imagecreatetruecolor($breite, $hoehe);
    if ($alpha) {
        imagealphablending($b, false);
        imagesavealpha($b, true);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        $blau = imagecolorallocatealpha($b, 20, 60, 200, 0);
        imagefilledrectangle($b, intdiv($breite, 4), intdiv($hoehe, 4), intdiv($breite * 3, 4), intdiv($hoehe * 3, 4), $blau);
        return $b;
    }
    for ($y = 0; $y < $hoehe; $y++) {
        $g = intdiv(255 * $y, max(1, $hoehe - 1));
        imageline($b, 0, $y, $breite - 1, $y, imagecolorallocate($b, 90, $g, 255 - $g));
    }
    mt_srand($breite * 7 + $hoehe);
    for ($i = 0; $i < 300; $i++) {
        $farbe = imagecolorallocate($b, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        imageellipse($b, mt_rand(0, $breite), mt_rand(0, $hoehe), mt_rand(5, 300), mt_rand(5, 300), $farbe);
    }
    imagefilledrectangle($b, 0, 0, intdiv($breite, 10), intdiv($hoehe, 10), imagecolorallocate($b, 255, 0, 0));
    return $b;
}

function speichereJpeg(\GdImage $b, string $datei, int $qualitaet = 90): string
{
    imagejpeg($b, $datei, $qualitaet);
    return $datei;
}

/**
 * Setzt einen EXIF-Block (APP1) direkt hinter den JPEG-Anfang: Kamera, Ausrichtung, GPS.
 * Der Standort ist erfunden (12°34'56" N, 65°43'21" O, mitten im Meer).
 */
function mitExif(string $datei, int $ausrichtung = 1): string
{
    $kurz = static fn(int $v) => pack('v', $v);
    $lang = static fn(int $v) => pack('V', $v);
    $eintrag = static fn(int $tag, int $typ, int $anzahl, string $wert) => pack('vvV', $tag, $typ, $anzahl) . str_pad($wert, 4, "\0");
    $rational = static fn(array $werte) => implode('', array_map(static fn($w) => pack('VV', $w[0], $w[1]), $werte));

    $marke = "Testkamera\0\0"; // 12 Byte, gerade Länge
    $ifd0 = 8;
    $markeOff = $ifd0 + 2 + 3 * 12 + 4;            // 50
    $gpsOff = $markeOff + strlen($marke);          // 62
    $breiteOff = $gpsOff + 2 + 4 * 12 + 4;          // 116
    $laengeOff = $breiteOff + 24;                   // 140

    $tiff = "II*\0" . $lang($ifd0)
        . $kurz(3)
        . $eintrag(0x010F, 2, strlen($marke) - 1, $lang($markeOff))   // Make
        . $eintrag(0x0112, 3, 1, $kurz($ausrichtung))                 // Orientation
        . $eintrag(0x8825, 4, 1, $lang($gpsOff))                      // GPS-IFD
        . $lang(0)
        . $marke
        . $kurz(4)
        . $eintrag(0x0001, 2, 2, "N\0")
        . $eintrag(0x0002, 5, 3, $lang($breiteOff))
        . $eintrag(0x0003, 2, 2, "E\0")
        . $eintrag(0x0004, 5, 3, $lang($laengeOff))
        . $lang(0)
        . $rational([[12, 1], [34, 1], [56, 1]])
        . $rational([[65, 1], [43, 1], [21, 1]]);
    $app1 = "Exif\0\0" . $tiff;
    $jpeg = file_get_contents($datei);
    wahr(substr($jpeg, 0, 2) === "\xFF\xD8", 'kein JPEG');
    file_put_contents($datei, "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
    clearstatcache(true, $datei);
    return $datei;
}

/** Hat die Datei noch einen GPS-Standort? */
function hatGps(string $datei): bool
{
    $exif = @exif_read_data($datei, 'GPS', true);
    return is_array($exif) && isset($exif['GPS']['GPSLatitude']);
}

/** Erste Quantisierungstabelle (Helligkeit) eines JPEG, in Zickzack-Reihenfolge wie in der Datei. */
function quantTabelle(string $datei): array
{
    $d = file_get_contents($datei);
    $pos = 2;
    while ($pos + 4 <= strlen($d)) {
        if ($d[$pos] !== "\xFF") {
            break;
        }
        $marker = ord($d[$pos + 1]);
        $laenge = unpack('n', substr($d, $pos + 2, 2))[1];
        if ($marker === 0xDB) {
            return array_values(unpack('C64', substr($d, $pos + 5, 64)));
        }
        $pos += 2 + $laenge;
    }
    throw new PruefFehler('keine Quantisierungstabelle gefunden');
}

/** Tabelle, die libjpeg für eine Qualitätsstufe schreibt (Standardtabelle, Zickzack). */
function erwarteteTabelle(int $q): array
{
    $basis = [16, 11, 10, 16, 24, 40, 51, 61, 12, 12, 14, 19, 26, 58, 60, 55, 14, 13, 16, 24, 40, 57, 69, 56,
        14, 17, 22, 29, 51, 87, 80, 62, 18, 22, 37, 56, 68, 109, 103, 77, 24, 35, 55, 64, 81, 104, 113, 92,
        49, 64, 78, 87, 103, 121, 120, 101, 72, 92, 95, 98, 112, 100, 103, 99];
    $zickzack = [0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5, 12, 19, 26, 33, 40, 48, 41, 34, 27, 20,
        13, 6, 7, 14, 21, 28, 35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51, 58, 59, 52, 45, 38,
        31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63];
    $faktor = $q < 50 ? intdiv(5000, $q) : 200 - 2 * $q;
    return array_map(static fn($i) => max(1, min(255, intdiv($basis[$i] * $faktor + 50, 100))), $zickzack);
}

/** Progressives JPEG (SOF2) statt Baseline (SOF0)? */
function istProgressiv(string $datei): bool
{
    $d = file_get_contents($datei);
    return str_contains($d, "\xFF\xC2") && !str_contains(substr($d, 0, (int)strpos($d, "\xFF\xDA")), "\xFF\xC0");
}

/** RGB(A) eines Pixels, a = 0 deckend … 127 durchsichtig. */
function pixel(string $datei, int $x, int $y): array
{
    $b = imagecreatefromstring(file_get_contents($datei));
    $f = imagecolorsforindex($b, imagecolorat($b, $x, $y));
    return ['r' => $f['red'], 'g' => $f['green'], 'b' => $f['blue'], 'a' => $f['alpha']];
}

function istRot(array $p): bool
{
    return $p['r'] > 200 && $p['g'] < 60 && $p['b'] < 60;
}
