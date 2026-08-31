<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir "terminant/{X}/avec/{Y}/{Z}" -> "terminant/{X}/avec/{Y}/{Z}/{W}"
 * (D-045, palier 3 de Family::WORD_LIST_TERMINANT_WITH_LETTER, symetrique de
 * App\Search\PrefixAvecThreeLettersLinks cote suffixe) : liens vers chaque TROISIEME lettre
 * "avec" supplementaire qui a au moins un resultat reel, meme suffixe.
 */
final class SuffixAvecThreeLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
