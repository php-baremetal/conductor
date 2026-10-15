<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use Composer\Composer;
use Composer\Package\PackageInterface;

/** Merges extra.baremetal of installed packages and root into baremetal.gen.json. */
final class Generator
{
    public const MANIFEST = 'baremetal.gen.json';

    public function __construct(private readonly Composer $composer)
    {
    }

    /** Build the manifest; paths relative to $root. */
    public function build(string $root): array
    {
        $installMgr = $this->composer->getInstallationManager();
        $local      = $this->composer->getRepositoryManager()->getLocalRepository();

        $project = $this->baremetal($this->composer->getPackage()) ?? [];

        // Project name from root composer.json, vendor prefix stripped.
        $rootName = $this->composer->getPackage()->getName();
        if (is_string($rootName) && $rootName !== '' && $rootName !== '__root__' && !isset($project['name'])) {
            $slash = strrpos($rootName, '/');
            $project['name'] = $slash === false ? $rootName : substr($rootName, $slash + 1);
        }

        // PHP version from config.platform.php; extra.baremetal.php-version overrides.
        if (!isset($project['php-version'])) {
            $platform = $this->composer->getConfig()->get('platform');
            if (is_array($platform) && isset($platform['php']) && is_string($platform['php'])) {
                $project['php-version'] = $platform['php'];
            }
        }

        $includeDirs = [];
        $buses       = [];       // bus => ['transport' => ?, 'drivers' => []]
        $idfRequires = [];
        $sources     = [];
        $extPackages = [];       // engine extensions delivered as packages (type bm-ext)
        $components  = [];       // full ESP-IDF components delivered as packages (type bm-component)
        $hals        = [];       // generic per-arch HALs for arch-coupled bm-ext extensions (type bm-hal)
        $eventsHal   = null;     // the arch backing of the event-bus HAL (type bm-events-hal)
        $componentHals = [];     // per-arch HALs compiled into a bm-component (type bm-component-hal)

        foreach ($local->getCanonicalPackages() as $pkg) {
            $meta = $this->baremetal($pkg);
            if ($meta === null) {
                continue;
            }
            $base = $installMgr->getInstallPath($pkg);

            switch ($meta['kind'] ?? '') {
                case 'contract':
                    if (isset($meta['include-dir'])) {
                        $includeDirs[] = $this->rel($root, $base . '/' . $meta['include-dir']);
                    }
                    break;

                case 'transport': {
                    $bus = $meta['bus'] ?? 'i2c';
                    $buses[$bus] ??= ['transport' => null, 'drivers' => []];
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $buses[$bus]['transport'] = [
                        'package'      => $pkg->getName(),
                        'arch'         => $meta['arch'] ?? null,
                        'src-dir'      => $this->rel($root, $src),
                        'idf-requires' => $meta['idf-requires'] ?? [],
                    ];
                    $sources[]   = $this->rel($root, $src);
                    $idfRequires = array_merge($idfRequires, $meta['idf-requires'] ?? []);
                    break;
                }

                case 'driver': {
                    $bus = $meta['bus'] ?? 'i2c';
                    $buses[$bus] ??= ['transport' => null, 'drivers' => []];
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $buses[$bus]['drivers'][] = [
                        'package'    => $pkg->getName(),
                        'driver'     => $meta['driver'] ?? null,
                        'php-class'  => $meta['php-class'] ?? null,
                        'capability' => $meta['capability'] ?? null,
                        'src-dir'    => $this->rel($root, $src),
                    ];
                    $sources[] = $this->rel($root, $src);
                    break;
                }

                case 'ext': {
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $extPackages[] = [
                        'package'      => $pkg->getName(),
                        'name'         => $meta['ext'] ?? null,
                        'src-dir'      => $this->rel($root, $src),
                        'idf-requires' => $meta['idf-requires'] ?? [],
                        'priority'     => $meta['priority'] ?? 100,
                    ];
                    $sources[]   = $this->rel($root, $src);
                    $idfRequires = array_merge($idfRequires, $meta['idf-requires'] ?? []);
                    break;
                }

                // Per-arch HAL of a bm-ext extension (compiled by the aggregator).
                case 'hal': {
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $hals[$meta['hal'] ?? $pkg->getName()] = [
                        'package'      => $pkg->getName(),
                        'arch'         => $meta['arch'] ?? null,
                        'src-dir'      => $this->rel($root, $src),
                        'idf-requires' => $meta['idf-requires'] ?? [],
                    ];
                    $sources[]   = $this->rel($root, $src);
                    $idfRequires = array_merge($idfRequires, $meta['idf-requires'] ?? []);
                    break;
                }

                // Arch backing of the event-bus HAL, compiled into ext-events.
                case 'events-hal': {
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $eventsHal = [
                        'package'      => $pkg->getName(),
                        'arch'         => $meta['arch'] ?? null,
                        'src-dir'      => $this->rel($root, $src),
                        'idf-requires' => $meta['idf-requires'] ?? [],
                    ];
                    $sources[]   = $this->rel($root, $src);
                    $idfRequires = array_merge($idfRequires, $meta['idf-requires'] ?? []);
                    break;
                }

                // Arch backing of a bm-component HAL, keyed by HAL name.
                case 'component-hal': {
                    $src = $base . '/' . ($meta['src-dir'] ?? 'src');
                    $componentHals[$meta['hal'] ?? $pkg->getName()] = [
                        'package'      => $pkg->getName(),
                        'arch'         => $meta['arch'] ?? null,
                        'src-dir'      => $this->rel($root, $src),
                        'idf-requires' => $meta['idf-requires'] ?? [],
                    ];
                    break;
                }

                // Full ESP-IDF component, built in place via EXTRA_COMPONENT_DIRS.
                case 'component': {
                    $components[] = [
                        'package'   => $pkg->getName(),
                        'component' => basename($base),
                        'dir'       => $this->rel($root, $base),
                        'module'    => $meta['module'] ?? null,
                        'priority'  => $meta['priority'] ?? 100,
                    ];
                    break;
                }
            }
        }

        usort($extPackages, fn($a, $b) => $a['priority'] <=> $b['priority']);
        usort($components, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Per-bus driver list -> -DPHP_<BUS>_DRIVERS.
        $driverNames = [];
        foreach ($buses as $bus => $b) {
            $names = [];
            foreach ($b['drivers'] as $d) {
                if ($d['driver'] !== null) {
                    $names[] = $d['driver'];
                }
            }
            $driverNames[$bus] = $names;
        }

        return [
            'generated-by' => 'php-baremetal/conductor',
            'project'      => $project,
            'php-version'  => $project['php-version'] ?? null,
            'storage'      => $this->storage($project),
            'include-dirs' => array_values(array_unique($includeDirs)),
            'sources'      => array_values(array_unique($sources)),
            'idf-requires' => array_values(array_unique($idfRequires)),
            'driver-names' => (object) $driverNames,
            'buses'        => (object) $buses,
            'ext-packages' => $extPackages,
            'components'   => $components,
            'hals'         => (object) $hals,
            'events'       => $eventsHal === null ? null : ['hal' => $eventsHal],
            'component-hals' => (object) $componentHals,
        ];
    }

    /** Normalize storage config to { type, microsd? }. */
    private function storage(array $project): array
    {
        $s = $project['storage'] ?? null;
        if (is_string($s)) {
            return ['type' => $s];
        }
        if (is_array($s)) {
            $type = $s['type'] ?? (isset($s['microsd']) ? 'microsd' : (isset($s['embedded']) ? 'embedded' : null));
            $out  = ['type' => $type];
            // true = default pins; object = SPI pin overrides.
            if (isset($s['microsd'])) {
                if ($s['microsd'] === true) {
                    $out['microsd'] = true;
                } elseif (is_array($s['microsd'])) {
                    $out['microsd'] = $s['microsd'];
                }
            }
            return $out;
        }
        return ['type' => null];
    }

    /** Validate the graph; returns problems (empty = ok). */
    public function validate(array $manifest): array
    {
        $problems = [];
        foreach ($manifest['buses'] as $bus => $b) {
            if ($b['drivers'] !== [] && $b['transport'] === null) {
                $problems[] = sprintf(
                    "bus '%s' has %d driver(s) but no transport provider installed",
                    $bus,
                    count($b['drivers'])
                );
            }
            foreach ($b['drivers'] as $d) {
                if (($d['driver'] ?? null) === null) {
                    $problems[] = sprintf("a driver in bus '%s' (%s) declares no 'driver' name", $bus, $d['package']);
                }
            }
        }
        if (($manifest['project']['php-version'] ?? null) === null) {
            $problems[] = "project extra.baremetal has no 'php-version'";
        }

        $storage = $manifest['storage'] ?? ['type' => null];
        $type    = $storage['type'] ?? null;
        if ($type === null) {
            $problems[] = "project storage has no type (expected 'embedded' or 'microsd')";
        } elseif ($type === 'microsd') {
            $sd = $storage['microsd'] ?? [];
            if (($sd['mode'] ?? null) === 'spi') {
                foreach (['cs', 'sclk', 'mosi', 'miso'] as $pin) {
                    if (!isset($sd[$pin]) || !is_int($sd[$pin])) {
                        $problems[] = "storage.microsd (spi) is missing integer pin '{$pin}'";
                    }
                }
            }
        }
        return $problems;
    }

    private function baremetal(PackageInterface $pkg): ?array
    {
        $extra = $pkg->getExtra();
        $meta  = $extra['baremetal'] ?? null;
        return is_array($meta) ? $meta : null;
    }

    /** $path relative to $root when under it. */
    private function rel(string $root, string $path): string
    {
        $root = rtrim($root, '/') . '/';
        $real = $this->canonical($path);
        $base = $this->canonical(rtrim($root, '/'));
        if ($base !== '' && str_starts_with($real, $base . '/')) {
            return substr($real, strlen($base) + 1);
        }
        return $real !== '' ? $real : $path;
    }

    /** Collapse ./ and ../ without touching the filesystem. */
    private function canonical(string $path): string
    {
        $abs   = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return ($abs ? '/' : '') . implode('/', $parts);
    }
}
