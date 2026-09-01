<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\AvecBareTwoLettersLinks depuis list_counts (list_type 'avec_bare_pair'),
 * meme principe que App\Search\AvecTwoLettersLinksBuilder / App\Search\PrefixAvecLinksBuilder --
 * une seule requete triviale, aucun calcul sur `terms` au runtime (voir
 * scripts/build_explore_hub_counts.php pour le precalcul).
 *
 * list_key est toujours "{lettre1}:{lettre2}" avec lettre1 < lettre2 (ordre alphabetique, meme
 * convention que 'length_with_pair'). Depuis une page "avec {X}" bare (palier 1, hub /mots
 * App\Search\ExploreHub::$byWith), $letter peut se trouver des DEUX cotes de la paire stockee
 * selon l'ordre alphabetique avec son partenaire : cette classe interroge donc les deux cas avec
 * un OR sur deux motifs LIKE ({letter}:% et %:{letter}), une seule requete, jamais deux
 * executions separees -- meme mecanisme exact que AvecTwoLettersLinksBuilder, sans le prefixe de
 * longueur. La table est petite (325 lignes au maximum, C(26,2)) : le second motif LIKE (joker
 * en tete) reste negligeable a cette taille.
 *
 * L'URL cible est TOUJOURS construite via WordListFilters::fromPath()->canonicalUrl(), jamais
 * assemblee a la main : ksort() y trie deja les lettres "avec" par cle alphabetique (D-022), donc
 * peu importe l'ordre dans lequel $letter et le partenaire sont passes a fromPath() ici, l'URL
 * rendue est toujours la forme canonique (lettre1 < lettre2), identique a la cle list_counts.
 */
final class AvecBareTwoLettersLinksBuilder
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
     * Doublons de contenu avec la page PARENTE palier 1 (D-049, meme methodologie que
     * App\Search\AvecTwoLettersLinksBuilder::DUPLICATE_PARENT_KEYS un niveau plus bas) : une
     * paire dont le compte egale celui d'UNE des deux lettres seules -- CORRECTIF trouve en
     * revisant ce meme builder AVANT toute application (pas apres, contrairement a D-047) :
     * sans cette constante, corriger le registre (noindex,follow + canonical) sans repercuter
     * ici laisserait ce builder generer des liens VIVANTS vers des pages devenues noindex
     * (violation R5, exactement le defaut trouve et corrige apres coup pour D-045/D-047).
     * SOURCE DE VERITE = scripts/seo-batches/avec-bare-two-letters-2026-09-01.php.
     *
     * @var list<string>
     */
    private const DUPLICATE_PARENT_KEYS = [];

    /**
     * Doublons de contenu entre pages SOEURS du meme palier (D-049, empreinte SQL reelle,
     * meme methodologie que App\Search\AvecTwoLettersLinksBuilder::SIBLING_DUPLICATE_KEYS).
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE deja indexee (D-049, meme
     * discipline D-041 -- balayage generique contre TOUTES les familles combinatoires
     * index,follow, pas seulement la hierarchie "avec" bare elle-meme).
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [];

    /**
     * Paires HORS BUDGET TTFB (D-049, PAS une exclusion de contenu duplique -- meme mecanisme de
     * code que les trois constantes ci-dessus, raison differente) : voir docs/DECISIONS.md D-049
     * pour la methodologie de mesure complete (balayage exhaustif PUIS remesure ciblee mediane
     * de 3, contention inter-processus ecartee -- meme discipline que le depot allemand cousin,
     * D-DE-040/D-DE-046).
     *
     * SOURCE DE VERITE = le lot versionne lui-meme (scripts/seo-batches/avec-bare-two-letters-*.php).
     *
     * @var list<string>
     */
    private const OVER_BUDGET_KEYS = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $letter): AvecBareTwoLettersLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_pair'"
            . ' AND (list_key LIKE ? OR list_key LIKE ?)'
        );
        $statement->execute([$letter . ':%', '%:' . $letter]);

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

            $parts = explode(':', $key, 2);
            $partner = $parts[0] === $letter ? $parts[1] : $parts[0];
            $count = (int) $row['count'];

            $path = 'avec/' . strtolower($letter) . '/' . strtolower($partner);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $partner, 'url' => $url, 'count' => $count];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new AvecBareTwoLettersLinks(links: $links, queryCount: 1);
    }
}
