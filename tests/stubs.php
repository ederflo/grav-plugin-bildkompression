<?php
/**
 * Minimale Nachbildung der Grav-Klassen, die bildkompression.php braucht: Plugin-Basis,
 * Event, Konfiguration, Locator, Log und Seite. Nur das, was das Plugin aufruft.
 */
declare(strict_types=1);

namespace Grav\Common {
    class Plugin
    {
        public function __construct(public string $name, protected $grav, protected $config)
        {
        }
    }
}

namespace RocketTheme\Toolbox\Event {
    class Event implements \ArrayAccess
    {
        public function __construct(private array $daten = [])
        {
        }

        public function offsetExists(mixed $k): bool { return isset($this->daten[$k]); }
        public function offsetGet(mixed $k): mixed { return $this->daten[$k] ?? null; }
        public function offsetSet(mixed $k, mixed $v): void { $this->daten[$k] = $v; }
        public function offsetUnset(mixed $k): void { unset($this->daten[$k]); }
    }
}

namespace BildkompressionTest {
    class Konfig
    {
        public function __construct(private array $daten)
        {
        }

        public function get(string $pfad, mixed $standard = null): mixed
        {
            $w = $this->daten;
            foreach (explode('.', $pfad) as $teil) {
                if (!is_array($w) || !array_key_exists($teil, $w)) {
                    return $standard;
                }
                $w = $w[$teil];
            }
            return $w;
        }
    }

    /** Sammelt, was das Plugin ins Grav-Log schreibt. */
    class Log
    {
        public array $info = [];
        public array $warnung = [];

        public function info(string $text): void { $this->info[] = $text; }
        public function warning(string $text): void { $this->warnung[] = $text; }
    }

    /** Löst nur user://media auf. */
    class Locator
    {
        public function __construct(private string $media)
        {
        }

        public function findResource(string $uri, bool $absolut = true, bool $erstellen = false): string|false
        {
            return $uri === 'user://media' ? $this->media : false;
        }
    }

    class Seite
    {
        public function __construct(private string $ordner)
        {
        }

        public function path(): string { return $this->ordner; }
    }
}
