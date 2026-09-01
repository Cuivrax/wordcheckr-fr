<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage interne d'une page "mots avec {X} {Y}" bare (D-049, palier 2,
 * Family::WORD_LIST_AVEC_BARE_TWO_LETTERS, /mots/avec/{X}/{Y}) vers chaque variante "mots avec
 * {X} {Y} {Z}" (App\Seo\Family::WORD_LIST_AVEC_BARE_THREE_LETTERS, /mots/avec/{X}/{Y}/{Z}) qui a
 * au moins un resultat.
 *
 * Precalcule (list_counts, list_type 'avec_bare_triple', scripts/build_explore_hub_counts.php),
 * jamais un calcul sur `terms` au runtime. Voir App\Search\AvecBareThreeLettersLinksBuilder pour
 * le detail des trois positions possibles de la paire source dans le triplet stocke, et pour
 * OVER_BUDGET_KEYS.
 */
final class AvecBareThreeLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
