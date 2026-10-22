<?php
/**
 * Bildkompression für Uploads im Admin.
 *
 * Handyfotos kommen mit 10–20 MB und 4000+ px. Die Templates verkleinern zwar
 * beim Ausliefern, aber das Original liegt dann in voller Größe am Server und
 * die Galerie öffnet es beim Antippen ganz. Dieses Plugin verkleinert darum
 * jedes Bild gleich nach dem Upload (Admin 2 / API-Plugin) an Ort und Stelle.
 *
 * Quelle: /usr/local/share/grav-bildkompression  ->  verteilt mit grav-bildkompression-deploy.sh
 */
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\Bildkompression\Verkleinerer;
use RocketTheme\Toolbox\Event\Event;

require_once __DIR__ . '/classes/Verkleinerer.php';

class BildkompressionPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        // feuert das API-Plugin nach Uploads auf Seiten (page gesetzt) und in user://media (page = null)
        return ['onApiMediaUploaded' => ['nachUpload', 0]];
    }

    public function nachUpload(Event $event): void
    {
        $cfg = $this->config->get('plugins.bildkompression', []);
        if (empty($cfg['enabled'])) {
            return;
        }

        $page = $event['page'] ?? null;
        if ($page) {
            $ordner = $page->path();
        } else {
            $basis = $this->grav['locator']->findResource('user://media', true, true);
            $ordner = rtrim($basis . '/' . ($event['path'] ?? ''), '/');
        }
        if (!$ordner || !is_dir($ordner)) {
            return;
        }

        foreach ((array) ($event['filenames'] ?? []) as $name) {
            $datei = $ordner . '/' . basename((string) $name);
            try {
                $r = Verkleinerer::verkleinern(
                    $datei,
                    (int) ($cfg['max_kante'] ?? 2560),
                    (int) ($cfg['jpeg_qualitaet'] ?? 82),
                    (int) ($cfg['webp_qualitaet'] ?? 80)
                );
                if ($r && $r['ersetzt']) {
                    $this->grav['log']->info(sprintf(
                        'Bildkompression: %s %.1f MB -> %.1f MB (%dx%d)',
                        basename($datei), $r['vorher'] / 1048576, $r['nachher'] / 1048576, $r['breite'], $r['hoehe']
                    ));
                }
            } catch (\Throwable $e) {
                // Ein Bild, das sich nicht verkleinern lässt, bleibt einfach im Original liegen
                $this->grav['log']->warning('Bildkompression: ' . basename($datei) . ': ' . $e->getMessage());
            }
        }
    }
}
