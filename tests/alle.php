<?php
/**
 * Führt alle Prüfskripte nacheinander in eigenen PHP-Prozessen aus.
 * Aufruf: php tests/alle.php (Exit-Code 1, wenn ein Skript scheitert)
 */
declare(strict_types=1);

$fehler = 0;
foreach (['verkleinern.php', 'plugin.php'] as $skript) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $skript), $code);
    echo "\n";
    $fehler += $code === 0 ? 0 : 1;
}
echo $fehler ? "Bildkompression: $fehler Prüfskript(e) fehlgeschlagen\n" : "Bildkompression: alle Prüfungen bestanden\n";
exit($fehler ? 1 : 0);
