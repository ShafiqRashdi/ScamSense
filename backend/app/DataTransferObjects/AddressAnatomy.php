<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * A submitted address split into the parts a reader has to tell apart.
 *
 * The whole premise of the teaching panel: scam links copy a company's NAME
 * into the part of an address anyone can write, while the part that says who
 * owns it is somebody else's. Every field is already defanged.
 */
final readonly class AddressAnatomy
{
    public function __construct(
        /** Scheme and anything before the host, e.g. "hxxps://". */
        public string $lead,
        /** Labels in front of the owner, e.g. "maybank2u[.]" — may be empty. */
        public string $prefix,
        /** The registrable domain: who actually owns this address. */
        public string $owner,
        /** Port, path and query, e.g. "/login/update-account". */
        public string $tail,
        /** True when the prefix is imitating a known brand (brand_lookalike). */
        public bool $prefixIsCopiedName,
    ) {}
}
