<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage interne d'une page "mots avec {X} {Y} {Z}" bare (D-049, palier 3,
 * Family::WORD_LIST_AVEC_BARE_THREE_LETTERS, /mots/avec/{X}/{Y}/{Z}) vers chaque variante "mots
 * avec {X} {Y} {Z} {W}" (App\Seo\Family::WORD_LIST_AVEC_BARE_FOUR_LETTERS,
 * /mots/avec/{X}/{Y}/{Z}/{W}) qui a au moins un resultat -- dernier palier borne de la famille
 * "avec" bare.
 *
 * Precalcule (list_counts, list_type 'avec_bare_quad', scripts/build_explore_hub_counts.php),
 * jamais un calcul sur `terms` au runtime. Voir App\Search\AvecBareFourLettersLinksBuilder pour
 * le detail des quatre positions possibles du triplet source dans le quadruplet stocke, et pour
 * OVER_BUDGET_KEYS.
 */
final class AvecBareFourLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
