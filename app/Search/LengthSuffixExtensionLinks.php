<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Symetrique de App\Search\LengthPrefixExtensionLinks pour "/mots/{N}-lettres/terminant/
 * {1 lettre}" (D-044) : liens vers chaque extension d'UNE lettre supplementaire (suffixe de
 * 2 lettres, MEME longueur) qui a au moins un resultat reel.
 */
final class LengthSuffixExtensionLinks
{
    /** @param list<array{suffix: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
