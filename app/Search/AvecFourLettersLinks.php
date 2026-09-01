<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir "avec {X} {Y} {Z}" (palier 3, deja indexe) -> "avec {W} {X} {Y} {Z}"
 * (D-048, palier 4) : liens vers chaque QUATRIEME lettre supplementaire qui a au moins un
 * resultat reel, meme longueur.
 */
final class AvecFourLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
