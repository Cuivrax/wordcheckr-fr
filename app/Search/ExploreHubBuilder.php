<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\ExploreHub depuis la table list_counts, precalculee hors ligne par
 * scripts/build_explore_hub_counts.php.
 *
 * Mesure qui a impose ce detour (pas de GROUP BY direct au runtime) : un GROUP BY sur
 * substr(normalized,1,1) / substr(reversed,1,1) n'a aucun index disponible sur l'expression
 * calculee -- 245 ms et 215 ms mesures sur les 838 180 lignes reelles (SCAN complet + TEMP
 * B-TREE), tres au-dessus du budget TTFB p95 < 250 ms pour une seule page (CLAUDE.md).
 *
 * CORRECTIF (D-049, 2026-09-01) : le docblock precedent affirmait "la table list_counts (66
 * lignes fixes) rend cette lecture triviale" -- ce chiffre decrivait l'etat initial du site,
 * avant l'ouverture des paliers combinatoires (D-029 a D-048) : list_counts contient reellement
 * 324 915 lignes a ce jour (30 list_type), dont seules 92 sont utiles a ce hub une fois
 * 'avec_bare' ajoute par ce meme correctif (14 'length' + 26 'start' + 26 'end' + 26
 * 'avec_bare'). L'ancien `SELECT * FROM list_counts` (sans WHERE, jamais prepare) lisait et
 * iterait les 324 915 lignes en PHP pour n'en retenir qu'une poignee -- confirme en direct :
 * 338,8 ms mesures sur /mots AVANT ce correctif, deja au-dessus du budget TTFB p95 < 250 ms
 * (CLAUDE.md), jamais mesure depuis la croissance de list_counts (D-045 a D-048). Meme defaut
 * deja trouve et corrige sur le depot allemand cousin (D-DE-C1) -- meme correctif applique ici :
 * requete PREPAREE, bornee par WHERE list_type IN (?, ?, ?, ?) LIMIT, jamais un SELECT * non
 * borne.
 *
 * MAILLAGE "avec" SANS ANCRAGE (D-049, cette passe) : le hub expose desormais aussi une
 * quatrieme grille, `byWith` (26 lettres, 'avec_bare' dans list_counts) -- "/mots/avec/{X}"
 * seul, sans longueur/prefixe/suffixe. Contrairement a WORD_LIST_AVEC_SINGLE_LETTER/
 * _TWO_LETTERS/_THREE_LETTERS/_FOUR_LETTERS (D-029 a D-048, TOUJOURS une longueur, jamais
 * rendues depuis ce hub), ce palier n'a structurellement AUCUN autre point d'entree possible --
 * il n'existe pas de page "avec longueur" parente a etendre pour le cas bare, donc le hub
 * LUI-MEME doit porter la grille -- meme precedent que le maillage bare du depot allemand cousin
 * (D-DE-040). App\Search\AvecBareTwoLettersLinksBuilder prend ensuite le relais depuis chaque
 * page /mots/avec/{X} pour lister les paires /mots/avec/{X}/{Y}.
 *
 * Budget runtime : 1 requete SQLite preparee, bornee par WHERE list_type IN (?,?,?,?) LIMIT,
 * aucun GROUP BY, aucun SCAN de `terms` -- tres en-dessous du plafond de moins de 10
 * (CLAUDE.md).
 */
final class ExploreHubBuilder
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(): ExploreHub
    {
        // CORRECTIF D-049 : requete preparee, bornee a list_type IN ('length','start','end',
        // 'avec_bare') -- les 4 seuls list_type consommes par le switch ci-dessous -- avec un
        // LIMIT explicite (90, marge au-dessus des 92 lignes utiles reelles attendues : 14
        // longueurs + 26 'start' + 26 'end' + 26 'avec_bare' = 92 -- LIMIT porte a 120 pour
        // garder une marge de securite identique en proportion au dimensionnement d'origine)
        // plutot qu'un SELECT * FROM list_counts non prepare et non borne (324 915 lignes, 30
        // list_type -- voir le docblock de classe pour la mesure avant/apres : 338,8 ms -> voir
        // rapport de tache pour la mesure apres correctif).
        $statement = $this->connection->pdo()->prepare(
            'SELECT list_type, list_key, count FROM list_counts WHERE list_type IN (?, ?, ?, ?) LIMIT 120'
        );
        $statement->execute(['length', 'start', 'end', 'avec_bare']);

        $byLength = [];
        $byStart = [];
        $byEnd = [];
        $byWith = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];
            $count = (int) $row['count'];

            switch ($row['list_type']) {
                case 'length':
                    $url = WordListFilters::fromPath($key . '-lettres')?->canonicalUrl();

                    if ($url !== null) {
                        $byLength[] = ['length' => (int) $key, 'url' => $url, 'count' => $count];
                    }
                    break;

                case 'start':
                    $url = WordListFilters::fromPath('commencant/' . strtolower($key))?->canonicalUrl();

                    if ($url !== null) {
                        $byStart[] = ['letter' => $key, 'url' => $url, 'count' => $count];
                    }
                    break;

                case 'end':
                    $url = WordListFilters::fromPath('terminant/' . strtolower($key))?->canonicalUrl();

                    if ($url !== null) {
                        $byEnd[] = ['letter' => $key, 'url' => $url, 'count' => $count];
                    }
                    break;

                // D-049 : "avec/{X}" seul (sans longueur/prefixe/suffixe).
                case 'avec_bare':
                    $url = WordListFilters::fromPath('avec/' . strtolower($key))?->canonicalUrl();

                    if ($url !== null) {
                        $byWith[] = ['letter' => $key, 'url' => $url, 'count' => $count];
                    }
                    break;
            }
        }

        usort($byLength, static fn (array $a, array $b): int => $a['length'] <=> $b['length']);
        usort($byStart, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);
        usort($byEnd, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);
        usort($byWith, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new ExploreHub(byLength: $byLength, byStart: $byStart, byEnd: $byEnd, byWith: $byWith, queryCount: 1);
    }
}
