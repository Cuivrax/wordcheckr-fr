<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir "terminant/{X}/avec/{Y}" -> "terminant/{X}/avec/{Y}/{Z}" (D-045,
 * palier 2 de Family::WORD_LIST_TERMINANT_WITH_LETTER, symetrique de
 * App\Search\PrefixAvecTwoLettersLinks cote suffixe) : liens vers chaque DEUXIEME lettre
 * "avec" supplementaire qui a au moins un resultat reel, meme suffixe.
 */
final class SuffixAvecTwoLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
