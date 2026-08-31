<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir "terminant/{X}" -> "terminant/{X}/avec/{Y}" (D-045, symetrique de
 * App\Search\PrefixAvecLinks cote suffixe -- Family::WORD_LIST_TERMINANT_WITH_LETTER, famille
 * entierement nouvelle) : liens vers chaque lettre "avec" qui a au moins un resultat reel,
 * meme suffixe.
 */
final class SuffixAvecLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
