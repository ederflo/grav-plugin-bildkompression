<?php
/**
 * Plugin-Klasse mit nachgebildetem Grav: Upload auf eine Seite, Upload nach user://media,
 * abgeschaltetes Plugin, Dateinamen mit Pfadanteilen, Log-Meldung.
 * Aufruf: php tests/plugin.php (Exit-Code 1, wenn eine Prüfung scheitert)
 */
declare(strict_types=1);

require __DIR__ . '/hilfen.php';
require __DIR__ . '/bilder.php';
require __DIR__ . '/stubs.php';
$ORDNER = getenv('BILDKOMPRESSION_ORDNER') ?: dirname(__DIR__);
require $ORDNER . '/bildkompression.php';

use BildkompressionTest\Konfig;
use BildkompressionTest\Locator;
use BildkompressionTest\Log;
use BildkompressionTest\Seite;
use Grav\Plugin\BildkompressionPlugin;
use RocketTheme\Toolbox\Event\Event;

echo "Bildkompression: Plugin\n\n";

$tmp = tempOrdner('bildkompression-plugin-');

/** @return array{0: BildkompressionPlugin, 1: Log} */
function plugin(string $media, array $einstellungen = []): array
{
    $cfg = array_replace(ladeYaml($GLOBALS['ORDNER'] . '/bildkompression.yaml'), $einstellungen);
    $log = new Log();
    $grav = ['locator' => new Locator($media), 'log' => $log];
    return [new BildkompressionPlugin('bildkompression', $grav, new Konfig(['plugins' => ['bildkompression' => $cfg]])), $log];
}

function ordner(string $pfad): string
{
    @mkdir($pfad, 0700, true);
    return $pfad;
}

pruefe('hängt am Upload-Event des API-Plugins', function () {
    gleich(['onApiMediaUploaded' => ['nachUpload', 0]], BildkompressionPlugin::getSubscribedEvents());
});

pruefe('Upload auf eine Seite: alle hochgeladenen Bilder verkleinert, Log-Meldung je Bild', function () use ($tmp) {
    $seite = ordner("$tmp/pages/01.galerie");
    speichereJpeg(testbild(2800, 2100), "$seite/eins.jpg", 95);
    speichereJpeg(testbild(1000, 700), "$seite/klein.jpg", 60);
    $unberuehrt = speichereJpeg(testbild(2800, 2100), "$seite/nicht-hochgeladen.jpg", 95);
    $md5 = md5_file($unberuehrt);
    [$p, $log] = plugin(ordner("$tmp/media"));
    $p->nachUpload(new Event(['page' => new Seite($seite), 'filenames' => ['eins.jpg', 'klein.jpg']]));
    gleich(2560, getimagesize("$seite/eins.jpg")[0]);
    gleich(1000, getimagesize("$seite/klein.jpg")[0]);
    gleich($md5, md5_file($unberuehrt), 'nicht hochgeladenes Bild wurde angefasst');
    gleich(1, count($log->info), 'eine Meldung (nur das ersetzte Bild)');
    wahr((bool)preg_match('/^Bildkompression: eins\.jpg \d+\.\d MB -> \d+\.\d MB \(2560x1920\)$/', $log->info[0]), $log->info[0]);
    gleich([], $log->warnung);
});

pruefe('Upload nach user://media mit Unterordner', function () use ($tmp) {
    $media = ordner("$tmp/media2");
    ordner("$media/titelbilder");
    speichereJpeg(testbild(2800, 2100), "$media/titelbilder/titel.jpg", 95);
    [$p] = plugin($media);
    $p->nachUpload(new Event(['page' => null, 'path' => 'titelbilder', 'filenames' => ['titel.jpg']]));
    gleich(2560, getimagesize("$media/titelbilder/titel.jpg")[0]);
    // ohne path: direkt in user://media
    speichereJpeg(testbild(2800, 2100), "$media/direkt.jpg", 95);
    $p->nachUpload(new Event(['filenames' => ['direkt.jpg']]));
    gleich(2560, getimagesize("$media/direkt.jpg")[0]);
});

pruefe('Dateinamen mit Pfadanteilen bleiben im Upload-Ordner', function () use ($tmp) {
    $seite = ordner("$tmp/pages/02.sicher");
    $daneben = speichereJpeg(testbild(2800, 2100), "$tmp/pages/daneben.jpg", 95);
    $md5 = md5_file($daneben);
    [$p, $log] = plugin(ordner("$tmp/media3"));
    $p->nachUpload(new Event(['page' => new Seite($seite), 'filenames' => ['../daneben.jpg', '/etc/passwd']]));
    gleich($md5, md5_file($daneben), 'Datei außerhalb des Seitenordners verändert');
    gleich([], $log->info);
});

pruefe('abgeschaltet, fehlender Ordner oder Nicht-Bild: nichts passiert, kein Fehler', function () use ($tmp) {
    $seite = ordner("$tmp/pages/03.aus");
    $f = speichereJpeg(testbild(2800, 2100), "$seite/gross.jpg", 95);
    $md5 = md5_file($f);
    [$aus, $log] = plugin(ordner("$tmp/media4"), ['enabled' => false]);
    $aus->nachUpload(new Event(['page' => new Seite($seite), 'filenames' => ['gross.jpg']]));
    gleich($md5, md5_file($f), 'abgeschaltetes Plugin hat verkleinert');
    [$p, $log] = plugin("$tmp/gibt-es-nicht");
    $p->nachUpload(new Event(['page' => null, 'path' => 'x', 'filenames' => ['gross.jpg']]));
    $p->nachUpload(new Event(['page' => new Seite("$tmp/auch-nicht"), 'filenames' => ['gross.jpg']]));
    file_put_contents("$seite/anleitung.pdf", "%PDF-1.4\n");
    $p->nachUpload(new Event(['page' => new Seite($seite), 'filenames' => ['anleitung.pdf']]));
    gleich([], $log->info);
    gleich([], $log->warnung);
});

pruefe('Einstellungen aus der Konfiguration: Kantenlänge und JPEG-Qualität', function () use ($tmp) {
    $seite = ordner("$tmp/pages/04.eigen");
    speichereJpeg(testbild(2800, 2100), "$seite/eigen.jpg", 95);
    [$p] = plugin(ordner("$tmp/media5"), ['max_kante' => 1200, 'jpeg_qualitaet' => 65]);
    $p->nachUpload(new Event(['page' => new Seite($seite), 'filenames' => ['eigen.jpg']]));
    gleich([1200, 900], array_slice(getimagesize("$seite/eigen.jpg"), 0, 2));
    gleich(erwarteteTabelle(65), quantTabelle("$seite/eigen.jpg"));
});

fertig();
