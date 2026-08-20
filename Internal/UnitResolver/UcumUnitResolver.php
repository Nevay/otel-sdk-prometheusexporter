<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\UnitResolver;

use Nevay\OTelSDK\Prometheus\Internal\UnitResolver;
use Nevay\Ucum\Unit;
use Nevay\Ucum\UnitException;
use function abs;

/**
 * @internal
 */
final class UcumUnitResolver implements UnitResolver {

    private const UNITS = [
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

        # https://unitsofmeasure.org/ucum#iso1000
        'min' => ['minute', 'minutes'],
        'h' => ['hour', 'hours'],
        'd' => ['day', 'days'],
        'wk' => ['week', 'weeks'],
        'mo' => ['month', 'months'],
        'y' => ['year', 'years'],
        '%' => ['percent', 'percent'],
    ];

    private const PREFIXES = [
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

    public function __construct(
        private readonly array $units = self::UNITS,
        private readonly array $prefixes = self::PREFIXES,
    ) {}

    public function resolve(string $unit): ?string {
        if (isset($this->units[$unit])) {
            return $this->units[$unit][1];
        }

        try {
            $parsed = Unit::parse($unit);
        } catch (UnitException) {
            return $unit;
        }

        $s = '';
        foreach ($parsed->atoms() as $atom) {
            if ($atom->unit === '1') {
                continue;
            }

            match (true) {
                $s !== '' && $atom->exponent >= 0 => $s .= '_times_',
                $s !== '' && $atom->exponent < 0 => $s .= '_per_',
                $s === '' && $atom->exponent < 0 => $s .= 'per_',
                default => null,
            };

            $exponent = abs($atom->exponent);
            if ($exponent === 2 || $exponent === 3) {
                try {
                    Unit::convert($atom->unit, 'm');
                    $s .= match ($exponent) {
                        2 => 'square_',
                        3 => 'cubic_',
                    };
                    $exponent = 1;
                } catch (UnitException) {}
            }

            if ($atom->prefix !== null) {
                $s .= $this->prefixes[$atom->prefix] ?? $atom->prefix;
            }
            $s .= $this->units[$atom->unit][$atom->exponent >= 0] ?? $atom->unit;

            if ($exponent === 2) {
                $s .= '_squared';
                $exponent = 1;
            }

            if ($exponent !== 1) {
                $s .= '_pow_';
                $s .= $exponent;
            }
        }

        if ($s === '') {
            return null;
        }

        return $s;
    }
}
