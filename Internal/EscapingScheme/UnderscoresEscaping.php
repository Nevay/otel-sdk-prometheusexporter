<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;

use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;
use function ord;
use function strlen;
use function substr;

/**
 * @internal
 */
final class UnderscoresEscaping implements EscapingScheme {

    public function escapeName(string $name): string {
        $s = '';
        for ($i = 0, $n = strlen($name); $i < $n;) {
            $c = $name[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === ':') {
                $s .= $c;
                $i++;
            } else {
                $o = ord($c);
                $b = match (true) {
                    $o < 128 => 1,
                    $o < 224 => 2,
                    $o < 240 => 3,
                    default => 4,
                };
                if (($s[-1] ?? '') !== '_') {
                    $s .= '_';
                }
                $i += $b;
            }
        }

        $c = $s[0] ?? '';
        if (!(($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === ':' || $c === '_')) {
            $s[0] = '_';

            if (($s[1] === '_')) {
                $s = substr($s, 1);
            }
        }

        return $s;
    }
}
