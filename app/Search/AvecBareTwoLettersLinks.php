<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Maillage interne d'une page "mots avec {X}" SANS AUCUN ancrage (D-049,
 * Family::WORD_LIST_AVEC_BARE_SINGLE_LETTER, /mots/avec/{X}) vers chaque variante "mots avec
 * {X} {Y}" (App\Seo\Family::WORD_LIST_AVEC_BARE_TWO_LETTERS, /mots/avec/{X}/{Y}) qui a au moins
 * un resultat -- repond a "je veux les mots avec un A ET un Y" depuis la page "avec A"
 * generique, sans aucune longueur imposee.
 *
 * Distinct de App\Search\AvecTwoLettersLinks (D-029/D-030, WORD_LIST_AVEC_TWO_LETTERS, qui porte
 * TOUJOURS une longueur) : ce palier n'a structurellement AUCUNE page "avec longueur" parente a
 * etendre pour le cas bare -- seul le hub /mots (App\Search\ExploreHub::$byWith) peut servir de
 * point d'entree pour le palier 1, ce builder-ci pour le palier 2.
 *
 * Precalcule (list_counts, list_type 'avec_bare_pair', scripts/build_explore_hub_counts.php),
 * jamais un calcul sur `terms` au runtime. list_key est toujours "{lettre1}:{lettre2}" avec
 * lettre1 < lettre2 (ordre alphabetique, meme convention que 'length_with_pair') -- voir
 * App\Search\AvecBareTwoLettersLinksBuilder pour la maniere dont une lettre source unique
 * retrouve ses partenaires quel que soit son cote dans la paire stockee, et pour
 * OVER_BUDGET_KEYS (candidats exclus du lot SEO pour depassement du budget TTFB p95 < 250 ms --
 * meme mecanisme de code qu'EXTERNAL_DUPLICATE_KEYS ailleurs sur ce depot, raison differente).
 *
 * Convention de nom "BARE" alignee avec le depot allemand cousin (D-DE-040), voir
 * app/Seo/Family.php et docs/DECISIONS.md D-049.
 */
final class AvecBareTwoLettersLinks
{
    /** @param list<array{letter: string, url: string, count: int}> $links */
    public function __construct(
        public readonly array $links,
        public readonly int $queryCount,
    ) {
    }
}
