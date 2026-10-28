<?php
/**
 * Verkleinerer ohne Grav: Kantenlänge, Qualität, EXIF/GPS, Ausrichtung, kleine Bilder.
 * Aufruf: php tests/verkleinern.php (Exit-Code 1, wenn eine Prüfung scheitert)
 *
 * BILDKOMPRESSION_ORDNER=/pfad/zum/plugin prüft eine andere Kopie (z. B. eine absichtlich kaputte).
 */
declare(strict_types=1);

require __DIR__ . '/hilfen.php';
require __DIR__ . '/bilder.php';
$ORDNER = getenv('BILDKOMPRESSION_ORDNER') ?: dirname(__DIR__);
require $ORDNER . '/classes/Verkleinerer.php';

use Grav\Plugin\Bildkompression\Verkleinerer;

echo "Bildkompression: Verkleinerer\n\n";

$tmp = tempOrdner('bildkompression-test-');
$standard = ladeYaml($ORDNER . '/bildkompression.yaml');

/** Verkleinern mit den Werten aus bildkompression.yaml, wie das Plugin es aufruft. */
function verkleinern(string $datei): ?array
{
    $s = $GLOBALS['standard'];
    return Verkleinerer::verkleinern($datei, $s['max_kante'], $s['jpeg_qualitaet'], $s['webp_qualitaet']);
}

pruefe('Auslieferungszustand: längste Kante 2560 px, JPEG 82, WebP 80', function () use ($standard) {
    gleich(true, $standard['enabled']);
    gleich(2560, $standard['max_kante']);
    gleich(82, $standard['jpeg_qualitaet']);
    gleich(80, $standard['webp_qualitaet']);
});

pruefe('großes Handyfoto: auf 2560 px, Qualität 82, progressiv, ersetzt an Ort und Stelle', function () use ($tmp) {
    $f = speichereJpeg(testbild(2800, 2100), "$tmp/quer.jpg", 95);
    $vorher = filesize($f);
    $r = verkleinern($f);
    clearstatcache();
    gleich(['vorher' => $vorher, 'nachher' => filesize($f), 'ersetzt' => true, 'breite' => 2560, 'hoehe' => 1920], $r);
    gleich([2560, 1920, IMAGETYPE_JPEG], array_slice(getimagesize($f), 0, 3));
    wahr($r['nachher'] < $vorher, 'nicht kleiner geworden');
    gleich(erwarteteTabelle(82), quantTabelle($f), 'Quantisierungstabelle (Qualität 82)');
    wahr(istProgressiv($f), 'nicht progressiv');
    gleich([], glob("$tmp/*.tmp"), 'Zwischendatei übrig');
});

pruefe('Hochformat und genaue Grenze: 2100x2800 -> 1920x2560, 2561 -> 2560, 2560 bleibt', function () use ($tmp) {
    $hoch = speichereJpeg(testbild(2100, 2800), "$tmp/hoch.jpg");
    gleich([1920, 2560], [verkleinern($hoch)['breite'], getimagesize($hoch)[1]]);
    $knapp = speichereJpeg(testbild(2561, 1000), "$tmp/knapp.jpg");
    gleich(2560, verkleinern($knapp)['breite']);
    $genau = speichereJpeg(testbild(2560, 1000), "$tmp/genau.jpg", 60);
    gleich(2560, verkleinern($genau)['breite']);
    gleich(2560, getimagesize($genau)[0]);
});

pruefe('EXIF fällt weg: GPS-Standort und Kamera sind nach dem Verkleinern entfernt', function () use ($tmp) {
    $f = mitExif(speichereJpeg(testbild(2800, 2100), "$tmp/gps.jpg"));
    wahr(hatGps($f), 'Testbild hat keinen GPS-Standort');
    gleich(true, verkleinern($f)['ersetzt']);
    wahr(!hatGps($f), 'GPS-Standort noch vorhanden');
    $exif = @exif_read_data($f, null, true) ?: [];
    gleich(false, isset($exif['IFD0']['Make']), 'Kameradaten noch vorhanden');
    gleich(false, isset($exif['IFD0']['Orientation']), 'Ausrichtung noch als EXIF-Wert vorhanden');
});

pruefe('EXIF-Drehung wird eingerechnet: hochkant fotografiertes Bild steht danach aufrecht', function () use ($tmp) {
    // gespeichert quer 2800x2100, Orientation 6 = für die Anzeige 90° im Uhrzeigersinn drehen
    $f = mitExif(speichereJpeg(testbild(2800, 2100), "$tmp/gedreht.jpg"), 6);
    $r = verkleinern($f);
    gleich([1920, 2560], [$r['breite'], $r['hoehe']]);
    // die rote Ecke war oben links gespeichert und liegt nach der Drehung oben rechts
    wahr(istRot(pixel($f, 1920 - 40, 40)), 'rote Ecke nicht oben rechts');
    wahr(!istRot(pixel($f, 40, 40)), 'rote Ecke noch oben links');
    // Orientation 3 = 180°: rote Ecke unten rechts, Maße bleiben quer
    $g = mitExif(speichereJpeg(testbild(2800, 2100), "$tmp/kopf.jpg"), 3);
    gleich([2560, 1920], array_values(array_intersect_key(verkleinern($g), ['breite' => 0, 'hoehe' => 0])));
    wahr(istRot(pixel($g, 2560 - 40, 1920 - 40)), 'rote Ecke nicht unten rechts');
});

pruefe('kleines, gut komprimiertes Bild bleibt Byte für Byte unverändert', function () use ($tmp) {
    $f = speichereJpeg(testbild(1200, 900), "$tmp/klein.jpg", 60);
    $vorher = md5_file($f);
    $r = verkleinern($f);
    gleich(['ersetzt' => false, 'breite' => 1200, 'hoehe' => 900], array_intersect_key($r, ['ersetzt' => 0, 'breite' => 0, 'hoehe' => 0]));
    gleich($r['vorher'], $r['nachher']);
    gleich($vorher, md5_file($f));
    gleich([], glob("$tmp/*.tmp"), 'Zwischendatei übrig');
    $png = "$tmp/klein.png";
    imagepng(testbild(800, 600), $png, 9);
    $vorherPng = md5_file($png);
    gleich(false, verkleinern($png)['ersetzt']);
    gleich($vorherPng, md5_file($png));
});

pruefe('kleines, schlecht komprimiertes Bild wird nur neu kodiert, die Maße bleiben', function () use ($tmp) {
    $f = speichereJpeg(testbild(1200, 900), "$tmp/gross-datei.jpg", 100);
    $r = verkleinern($f);
    gleich(true, $r['ersetzt']);
    gleich([1200, 900], [$r['breite'], $r['hoehe']]);
    wahr($r['nachher'] < $r['vorher'], 'nicht kleiner');
    gleich(erwarteteTabelle(82), quantTabelle($f));
});

pruefe('PNG bleibt PNG, Transparenz bleibt erhalten', function () use ($tmp) {
    $f = "$tmp/logo.png";
    imagepng(testbild(3000, 2000, true), $f, 6);
    $r = verkleinern($f);
    gleich([2560, 1707], [$r['breite'], $r['hoehe']]);
    gleich(IMAGETYPE_PNG, getimagesize($f)[2]);
    gleich(127, pixel($f, 5, 5)['a'], 'Ecke nicht mehr durchsichtig');
    $mitte = pixel($f, 1280, 853);
    gleich(0, $mitte['a'], 'Mitte nicht mehr deckend');
    wahr($mitte['b'] > 150, 'Farbe verloren');
});

pruefe('WebP: verkleinert, bleibt WebP, Qualität wirkt', function () use ($tmp) {
    $f = "$tmp/foto.webp";
    imagewebp(testbild(2800, 2100), $f, 95);
    $r = verkleinern($f);
    gleich([2560, 1920], [$r['breite'], $r['hoehe']]);
    gleich(IMAGETYPE_WEBP, getimagesize($f)[2]);
    $niedrig = "$tmp/niedrig.webp";
    $hoch = "$tmp/hoch.webp";
    imagewebp(testbild(2800, 2100), $niedrig, 95);
    copy($niedrig, $hoch);
    Verkleinerer::verkleinern($niedrig, 2560, 82, 50);
    Verkleinerer::verkleinern($hoch, 2560, 82, 95);
    clearstatcache();
    wahr(filesize($niedrig) < filesize($hoch), 'webp_qualitaet ohne Wirkung');
});

pruefe('Dateirechte bleiben, andere Kantenlänge und Qualität aus den Parametern', function () use ($tmp) {
    $f = speichereJpeg(testbild(2800, 2100), "$tmp/rechte.jpg");
    chmod($f, 0640);
    $r = Verkleinerer::verkleinern($f, 1000, 70, 80);
    clearstatcache();
    gleich([1000, 750], [$r['breite'], $r['hoehe']]);
    gleich(0640, fileperms($f) & 0777);
    gleich(erwarteteTabelle(70), quantTabelle($f), 'Qualität 70');
});

pruefe('nichts zu tun: andere Endung, fehlende oder kaputte Datei, Pixelbombe', function () use ($tmp) {
    $pdf = "$tmp/dokument.pdf";
    file_put_contents($pdf, "%PDF-1.4\n");
    gleich(null, verkleinern($pdf));
    gleich(null, verkleinern("$tmp/gibt-es-nicht.jpg"));
    $kaputt = "$tmp/kaputt.jpg";
    file_put_contents($kaputt, random_bytes(2000));
    $inhalt = file_get_contents($kaputt);
    gleich(null, verkleinern($kaputt));
    gleich($inhalt, file_get_contents($kaputt));
    // PNG-Kopf mit 11000 x 11000 Pixeln (121 MP): wird nicht einmal geöffnet
    $bombe = "$tmp/bombe.png";
    $ihdr = pack('NNCCCCC', 11000, 11000, 8, 2, 0, 0, 0);
    file_put_contents($bombe, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)));
    gleich(null, verkleinern($bombe));
    gleich([], glob("$tmp/*.tmp"), 'Zwischendatei übrig');
});

fertig();
