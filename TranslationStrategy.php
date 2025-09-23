<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus;

enum TranslationStrategy {

    /**
     * Fully escapes metric names for classic Prometheus metric name compatibility, and includes appending type and unit suffixes.
     */
    case UnderscoreEscapingWithSuffixes;
    /**
     * Metric names will continue to escape special characters to _, but suffixes won’t be attached.
     */
    case UnderscoreEscapingWithoutSuffixes;
    /**
     * Disable changing special characters to _. Special suffixes like units and _total for counters will be attached.
     */
    case NoUTF8EscapingWithSuffixes;
    /**
     * Bypasses all metric and label name translation, passing them through unaltered.
     */
    case NoTranslation;
}
