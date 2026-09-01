<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\AvecBareThreeLettersLinks depuis list_counts (list_type
 * 'avec_bare_triple'), meme principe que App\Search\AvecBareTwoLettersLinksBuilder (palier 1 ->
 * 2) et App\Search\AvecThreeLettersLinksBuilder (palier 2 -> 3 ANCRE sur la longueur, famille
 * SOEUR) -- une seule requete triviale, aucun calcul sur `terms` au runtime (voir
 * scripts/build_explore_hub_counts.php pour le precalcul).
 *
 * list_key est toujours "{lettre1}:{lettre2}:{lettre3}" avec lettre1 < lettre2 < lettre3
 * ALPHABETIQUEMENT (une seule ligne par triplet non ordonne). Depuis une page "avec {X} {Y}"
 * bare (palier 2, deja triee $x < $y par WordListFilters::fromPath()), la paire source peut
 * occuper TROIS positions differentes dans le triplet trie stocke, selon ou tombe le troisieme
 * partenaire dans l'ordre alphabetique -- meme raisonnement exact que
 * App\Search\AvecThreeLettersLinksBuilder (voir son docblock pour le detail complet des trois
 * cas), simplement sans le prefixe de longueur dans la cle. Trois motifs LIKE distincts,
 * combines par un seul OR dans une seule requete.
 *
 * L'URL cible est TOUJOURS construite via WordListFilters::fromPath()->canonicalUrl(), jamais
 * assemblee a la main.
 */
final class AvecBareThreeLettersLinksBuilder
{
    // CORRECTIF PERF PROACTIF (2026-09-01) : meme classe de bug trouvee et corrigee dans
    // App\Search\AvecFourLettersLinksBuilder (in_array() lineaire sur des constantes de
    // plusieurs milliers d'elements, jusqu'a >30 min sur la verification exhaustive du test
    // associe) -- appliquee ici par avance : ces 4 constantes sont petites pour l'instant mais
    // vont grossir au fil du balayage de nettoyage post-publication (D-049), meme risque a terme.
    private static ?array $duplicateParentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $externalDuplicateKeySet = null;
    private static ?array $overBudgetKeySet = null;

    /**
     * Doublons de contenu avec une page PARENTE palier 1 ou 2 (D-049, transitif -- meme
     * methodologie que App\Search\AvecThreeLettersLinksBuilder::DUPLICATE_PARENT_KEYS un niveau
     * plus bas). CORRECTIF trouve en revisant ce builder AVANT toute application (jamais un
     * lien vivant vers une page devenue noindex, meme defaut deja trouve et corrige apres coup
     * pour D-045/D-047 -- voir docs/DECISIONS.md D-049).
     * SOURCE DE VERITE = scripts/seo-batches/avec-bare-three-letters-2026-09-01.php.
     *
     * @var list<string>
     */
    private const DUPLICATE_PARENT_KEYS = [
        'A:J:W', 'K:Q:U', 'Q:U:X',
    ];

    /**
     * Doublons de contenu entre pages SOEURS du meme palier (D-049, empreinte SQL reelle).
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE deja indexee (D-049, meme
     * discipline D-041).
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'C:V:W', 'C:W:X', 'D:J:W', 'D:V:W', 'F:J:Q', 'F:K:X', 'F:Q:W', 'F:Q:X',
        'F:V:W', 'F:X:Z', 'G:Q:W', 'G:V:W', 'G:W:X', 'H:J:Q', 'H:K:X', 'H:V:W',
        'H:W:X', 'J:K:Q', 'J:K:V', 'J:K:X', 'J:K:Z', 'J:L:W', 'J:M:W', 'J:Q:V',
        'J:Q:X', 'J:S:W', 'J:T:W', 'J:U:W', 'J:V:X', 'J:W:Y', 'J:W:Z', 'J:X:Y',
        'J:X:Z', 'K:P:X', 'K:Q:W', 'K:V:W', 'K:X:Z', 'L:V:W', 'M:Q:W', 'P:Q:W',
        'P:W:X', 'Q:V:W', 'Q:W:Z', 'U:W:X',
    ];

    /**
     * Triplets HORS BUDGET TTFB (D-049, meme mecanisme que les trois constantes ci-dessus, PAS
     * un doublon de contenu) -- source de verite = le lot versionne lui-meme
     * (scripts/seo-batches/avec-bare-three-letters-*.php). Voir docs/DECISIONS.md D-049 pour la
     * methodologie de mesure complete.
     *
     * @var list<string>
     */
    private const OVER_BUDGET_KEYS = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * $letter1 et $letter2 : les deux lettres "avec" de la page palier 2 bare source, dans
     * n'importe quel ordre (triees ici par defense, meme si l'appelant les passe deja triees --
     * WordListFilters ksort() garantit deja $letter1 < $letter2 quand elles viennent de
     * $filters->withLetters).
     */
    public function build(string $letter1, string $letter2): AvecBareThreeLettersLinks
    {
        $pair = [$letter1, $letter2];
        sort($pair, SORT_STRING);
        [$x, $y] = $pair;

        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_triple'"
            . ' AND (list_key LIKE ? OR list_key LIKE ? OR list_key LIKE ?)'
        );
        $statement->execute([
            $x . ':' . $y . ':%',
            $x . ':%:' . $y,
            '%:' . $x . ':' . $y,
        ]);

        self::$duplicateParentKeySet ??= array_flip(self::DUPLICATE_PARENT_KEYS);
        self::$siblingDuplicateKeySet ??= array_flip(self::SIBLING_DUPLICATE_KEYS);
        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);
        self::$overBudgetKeySet ??= array_flip(self::OVER_BUDGET_KEYS);

        $links = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];

            if (
                isset(self::$duplicateParentKeySet[$key])
                || isset(self::$siblingDuplicateKeySet[$key])
                || isset(self::$externalDuplicateKeySet[$key])
                || isset(self::$overBudgetKeySet[$key])
            ) {
                continue;
            }

            $parts = explode(':', $key, 3);

            $partner = null;
            foreach ($parts as $candidate) {
                if ($candidate !== $x && $candidate !== $y) {
                    $partner = $candidate;
                    break;
                }
            }

            if ($partner === null) {
                // Defensif, jamais attendu : $x et $y sont toujours distincts (page palier 2
                // source), donc exactement une des trois lettres du triplet stocke n'est ni $x
                // ni $y.
                continue;
            }

            $count = (int) $row['count'];
            $path = 'avec/' . strtolower($x) . '/' . strtolower($y) . '/' . strtolower($partner);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $partner, 'url' => $url, 'count' => $count];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new AvecBareThreeLettersLinks(links: $links, queryCount: 1);
    }
}
