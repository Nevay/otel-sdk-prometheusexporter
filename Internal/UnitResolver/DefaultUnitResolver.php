<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\UnitResolver;

use Nevay\OTelSDK\Prometheus\Internal\UnitResolver;
use function explode;
use function preg_replace;
use function str_starts_with;
use function substr;
use function substr_replace;

/**
 * @internal
 */
final class DefaultUnitResolver implements UnitResolver {

    private const BASE_UNITS = [
        // https://unitsofmeasure.org/ucum#section-Base-Units
        'm' => ['meter', 'meters'],
        's' => ['second', 'seconds'],
        'g' => ['gram', 'grams'],
        'rad' => ['radian', 'radians'],
        'K' => ['kelvin', 'kelvin'],
        'C' => ['coulomb', 'coulombs'],
        'cd' => ['candela', 'candelas'],

        # https://unitsofmeasure.org/ucum#section-Derived-Unit-Atoms
        'mol' => ['mole', 'moles'],
        'sr' => ['steradian', 'steradians'],
        'Hz' => ['hertz', 'hertz'],
        'N' => ['newton', 'newtons'],
        'Pa' => ['pascal', 'pascals'],
        'J' => ['joule', 'joules'],
        'W' => ['watt', 'watts'],
        'A' => ['ampere', 'amperes'],
        'V' => ['volt', 'volts'],
        'F' => ['farad', 'farads'],
        'Ohm' => ['ohm', 'ohms'],
        'S' => ['siemens', 'siemens'],
        'Wb' => ['weber', 'webers'],
        'Cel' => ['celsius', 'celsius'],
        'T' => ['tesla', 'teslas'],
        'H' => ['henry', 'henries'],
        'lm' => ['lumen', 'lumens'],
        'lx' => ['lux', 'lux'],
        'Bq' => ['becquerel', 'becquerels'],
        'Gy' => ['gray', 'grays'],
        'Sv' => ['sievert', 'sieverts'],

        # https://unitsofmeasure.org/ucum#section-Prefixes-and-Units-Used-in-Information-Technology
        'bit' => ['bit', 'bits'],
        'By' => ['byte', 'bytes'],
        'Bd' => ['baud', 'bauds'],
    ];

    private const UNITS = [
        'min' => ['minute', 'minutes'],
        'h' => ['hour', 'hours'],
        'd' => ['day', 'days'],
        'wk' => ['week', 'weeks'],
        'mo' => ['month', 'months'],
        'y' => ['year', 'years'],
        '%' => ['percent', 'percent'],
        '1' => ['ratio', 'ratio'],
    ];

    private const UNIT_PREFIXES = [
        # https://unitsofmeasure.org/ucum#section-Prefixes
        'Q' => 'quetta',
        'R' => 'ronna',
        'Y' => 'yotta',
        'Z' => 'zetta',
        'E' => 'exa',
        'P' => 'peta',
        'T' => 'tera',
        'G' => 'giga',
        'M' => 'mega',
        'k' => 'kilo',
        'h' => 'hecto',
        'da' => 'deka',
        'd' => 'deci',
        'c' => 'centi',
        'm' => 'milli',
        'u' => 'micro',
        'n' => 'nano',
        'p' => 'pico',
        'f' => 'femto',
        'a' => 'atto',
        'z' => 'zepto',
        'y' => 'yocto',

        # https://unitsofmeasure.org/ucum#section-Prefixes-and-Units-Used-in-Information-Technology
        'Ki' => 'kibi',
        'Mi' => 'mebi',
        'Gi' => 'gibi',
        'Ti' => 'tebi',
        'Pi' => 'pebi',
        'Ei' => 'exbi',
        'Zi' => 'zebi',
        'Yi' => 'yobi',
        'Ri' => 'robi',
        'Qi' => 'quebi',
    ];

    /**
     * @param array<string, array{0: string, 1: string}> $baseUnits
     * @param array<string, array{0: string, 1: string}> $units
     * @param array<string, string> $prefixes
     */
    public function __construct(
        private readonly array $baseUnits = self::BASE_UNITS,
        private readonly array $units = self::UNITS,
        private readonly array $prefixes = self::UNIT_PREFIXES,
    ) {}

    public function resolve(string $unit): ?string {
        $unit = preg_replace('/\{[^}]*+}/', '', $unit);
        $parts = explode('/', $unit);
        if ($parts[0] === '') {
            return null;
        }

        $unit = $this->unit($parts[0], 1);
        for ($i = 1; $i < count($parts); $i++) {
            if ($parts[$i] !== '') {
                $unit .= '_per_';
                $unit .= $this->unit($parts[$i], 0);
            }
        }

        return $unit;
    }

    private function unit(string $unit, int $index): string {
        if (($u = $this->baseUnits[$unit] ?? $this->units[$unit] ?? null) !== null) {
            return $u[$index];
        }

        foreach ($this->prefixes as $prefix => $value) {
            if (str_starts_with($unit, $prefix) && ($baseUnit = $this->baseUnits[substr($unit, strlen($prefix))] ?? null) !== null) {
                return $value . $baseUnit[$index];
            }
        }

        return $unit;
    }
}
