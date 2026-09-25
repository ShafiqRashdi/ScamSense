<?php

declare(strict_types=1);

namespace App\Services\ThreatIntel;

use App\DataTransferObjects\ThreatIntelResult;

/**
 * Queries every configured reputation provider.
 *
 * Providers are independent: one being unreachable must not prevent the others
 * answering, and neither failure prevents a verdict. The aggregator returns
 * whatever was obtained, and DetectionService decides what it means.
 */
class ThreatIntelAggregator
{
    public function __construct(
        private GoogleSafeBrowsingClient $safeBrowsing,
        private VirusTotalClient $virusTotal,
    ) {}

    /**
     * @return list<ThreatIntelResult>
     */
    public function lookup(string $url): array
    {
        return [
            $this->safeBrowsing->lookup($url),
            $this->virusTotal->lookup($url),
        ];
    }
}
