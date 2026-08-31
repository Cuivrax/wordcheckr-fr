<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir "commencant/{X}/avec/{Y}/{Z}" -> "commencant/{X}/avec/{Y}/{Z}/{W}"
 * (D-045, palier 3 de l'extension de Family::WORD_LIST_COMMENCANT_WITH_LETTER) : liens vers
 * chaque TROISIEME lettre "avec" supplementaire qui a au moins un resultat reel, meme prefixe.
 */
final class PrefixAvecThreeLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
