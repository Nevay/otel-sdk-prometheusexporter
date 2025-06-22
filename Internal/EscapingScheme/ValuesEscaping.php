<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;

use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;
use function dechex;
use function mb_ord;
use function ord;
use function strlen;
use function substr;

/**
 * @internal
 */
final class ValuesEscaping implements EscapingScheme {

    public function escapeName(string $name): string {
        $s = 'U__';
        for ($i = 0, $n = strlen($name); $i < $n;) {
            $c = $name[$i];
            if ($c === '_') {
                $s .= '__';
                $i++;
            } elseif (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === ':') {
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
                $h = dechex(mb_ord(substr($name, $i, $b), 'UTF-8') ?: 0xfffd);
                $s .= '_';
                if (strlen($h) & 1) {
                    $s .= '0';
                }
                $s .= $h;
                $s .= '_';
                $i += $b;
            }
        }

        return $s;
    }
}
