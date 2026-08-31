<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage en entonnoir d'une page "/mots/{N}-lettres/commencant/{1 lettre}" (D-044, demande
 * produit 2026-08-31 : volume de recherche reel mesure pour "longueur + prefixe de 2 lettres",
 * ex. "mot de 6 lettres commencant par ar" ~4,4k/mois -- absent du site jusqu'ici) : depuis
 * cette page, liens vers chaque extension d'UNE lettre supplementaire (prefixe de 2 lettres,
 * MEME longueur) qui a au moins un resultat reel.
 *
 * Precalcule (list_counts, list_type 'length_prefix2', scripts/build_explore_hub_counts.php),
 * jamais un GROUP BY sur `terms` au runtime -- meme principe que App\Search\
 * PrefixExtensionLinksBuilder (variante SANS longueur).
 */
final class LengthPrefixExtensionLinks
{
    /** @param list<array{prefix: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
