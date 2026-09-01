<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Page hub /mots (route jusque-la en 404, WordListSolver::solve('') renvoie null pour un
 * chemin vide -- voir public/index.php). Trois grilles completes vers les familles deja
 * indexees et deja finies (D-017) : longueur (14), commencant (26), terminant (26) -- 66
 * liens, chacun avec son compte reel, construits via WordListFilters::canonicalUrl() comme
 * partout ailleurs sur ce projet, jamais une URL assemblee a la main.
 *
 * "Contenant" n'a JAMAIS de grille ici : famille a combinaisons infinies
 * (App\Seo\Family::NEVER_SITEMAP), jamais indexable par defaut. La vue expose seulement un
 * champ de recherche borne (au plus 3 lettres, decision produit) qui soumet vers
 * /mots/contenant/{lettres} -- un outil, pas une liste de pages pre-generees.
 *
 * $byWith (D-049, cette passe) : quatrieme grille, 26 lettres "avec" SANS AUCUN ancrage (ni
 * longueur, ni prefixe, ni suffixe -- /mots/avec/{X} seul). Point d'entree OBLIGE pour ce palier
 * (contrairement a WORD_LIST_AVEC_SINGLE_LETTER/TWO_LETTERS/THREE_LETTERS/FOUR_LETTERS, qui
 * portent toujours une longueur et s'atteignent depuis /mots/{N}-lettres) : aucune page "avec
 * longueur" parente n'existe a etendre pour le cas bare, donc le hub lui-meme doit porter la
 * grille -- meme precedent que le maillage "avec" bare du depot allemand cousin (D-DE-040).
 * App\Search\AvecBareTwoLettersLinksBuilder prend ensuite le relais depuis chaque page
 * /mots/avec/{X} pour lister les paires /mots/avec/{X}/{Y}.
 */
final class ExploreHub
{
    /**
     * @param list<array{length: int, url: string, count: int}> $byLength
     * @param list<array{letter: string, url: string, count: int}> $byStart
     * @param list<array{letter: string, url: string, count: int}> $byEnd
     * @param list<array{letter: string, url: string, count: int}> $byWith
     */
    public function __construct(
        public readonly array $byLength,
        public readonly array $byStart,
        public readonly array $byEnd,
        public readonly array $byWith,
        public readonly int $queryCount,
    ) {
    }
}
