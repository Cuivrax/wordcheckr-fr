<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\PrefixAvecTwoLettersLinks depuis list_counts (list_type
 * 'start_with_pair'), meme principe que App\Search\AvecTwoLettersLinksBuilder (variante SANS
 * commencant) -- une seule requete triviale, aucun calcul sur `terms` au runtime.
 *
 * list_key est toujours "{prefixe}:{lettre1}:{lettre2}" avec lettre1 < lettre2 ALPHABETIQUEMENT
 * (une seule ligne par paire non ordonnee). Depuis une page palier 1 "commencant/{X}/avec/{Y}",
 * la lettre source $letter peut se trouver des DEUX cotes de la paire stockee selon l'ordre
 * alphabetique avec son partenaire -- meme OR a deux motifs que AvecTwoLettersLinksBuilder.
 *
 * Les combinaisons degenerees (une lettre avec = prefixe, D-032) sont deja absentes de
 * list_counts -- exclues AU PRECALCUL (scripts/build_explore_hub_counts.php), meme discipline
 * que 'start_with' (App\Search\PrefixAvecLinksBuilder).
 */
final class PrefixAvecTwoLettersLinksBuilder
{
    // CORRECTIF PERF (2026-09-01, meme pattern que AvecFourLettersLinksBuilder) : in_array($key,
    // self::X_KEYS, true) sur ces trois constantes est un parcours lineaire relance a CHAQUE
    // ligne list_counts examinee dans build(). Tables de hachage calculees UNE FOIS par process
    // (cache statique), lookups O(1) au lieu de O(n) -- aucun changement de contenu.
    private static ?array $duplicateParentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $externalDuplicateKeySet = null;

    /**
     * Doublons de contenu avec la page PARENTE palier 1 (D-045, meme methodologie que
     * App\Search\AvecTwoLettersLinksBuilder::DUPLICATE_PARENT_KEYS) : une ligne 'start_with_pair'
     * "{X}:{Y}:{Z}" est un doublon SI ET SEULEMENT SI son `count` egale le `count` de la ligne
     * parente 'start_with' "{X}:{Y}" OU "{X}:{Z}". Calculee PROGRAMMATIQUEMENT (comparaison
     * directe list_counts, chargement en memoire par prefixe), 46 cles trouvees sur les 7 246
     * candidats reels -- voir docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const DUPLICATE_PARENT_KEYS = [
        'A:Q:U', 'G:Q:U', 'H:Q:U', 'I:N:W', 'J:A:W', 'J:Q:U',
        'K:Q:U', 'L:Q:U', 'O:Q:U', 'Q:I:V', 'Q:K:U', 'Q:U:V',
        'Q:U:X', 'U:A:J', 'U:B:J', 'U:E:J', 'U:E:W', 'U:I:J',
        'U:I:W', 'U:J:M', 'U:J:N', 'U:J:S', 'U:J:T', 'W:A:J',
        'W:B:J', 'W:E:J', 'W:J:L', 'W:J:N', 'W:J:O', 'W:J:R',
        'W:J:U', 'X:A:Z', 'X:B:O', 'X:D:E', 'X:E:F', 'X:E:Q',
        'X:E:Z', 'X:F:O', 'X:G:Z', 'X:H:Z', 'X:I:Z', 'X:O:Z',
        'X:P:Z', 'X:Q:U', 'X:R:Z', 'Y:U:X',
    ];

    /**
     * Doublons de contenu entre pages SOEURS du meme prefixe (D-045, meme methodologie que
     * App\Search\AvecTwoLettersLinksBuilder::SIBLING_DUPLICATE_KEYS) : regroupement par
     * (prefixe, count), verification par empreinte (liste triee des mots reels du panier,
     * comparaison de chaines completes) sur les groupes candidats. 58 cles trouvees (perdantes,
     * canonicalisees vers la cle gagnante la plus petite alphabetiquement) -- voir
     * docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [
        'D:J:Y', 'I:L:W', 'J:T:W', 'J:W:Z', 'K:V:X', 'O:W:Y',
        'P:W:Y', 'P:W:Z', 'Q:J:U', 'Q:L:W', 'Q:T:W', 'Q:U:W',
        'T:F:J', 'U:K:P', 'U:P:W', 'V:K:T', 'W:H:X', 'W:K:Z',
        'W:O:X', 'W:P:Q', 'W:P:Y', 'W:Q:U', 'W:R:X', 'W:S:X',
        'X:B:M', 'X:B:P', 'X:B:U', 'X:B:Y', 'X:D:G', 'X:D:T',
        'X:F:N', 'X:F:Y', 'X:G:V', 'X:R:V', 'X:U:V', 'X:V:Y',
        'X:Y:Z', 'Y:B:J', 'Y:D:J', 'Y:F:W', 'Y:G:K', 'Y:H:P',
        'Y:I:W', 'Y:J:K', 'Y:J:L', 'Y:J:N', 'Y:J:R', 'Y:J:T',
        'Y:J:U', 'Y:K:W', 'Y:K:X', 'Y:M:W', 'Y:N:X', 'Y:R:W',
        'Y:R:X', 'Y:T:X', 'Z:J:R', 'Z:K:W',
    ];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE (D-045, meme discipline D-041) --
     * remplie par D-047 (balayage generique complet post-D-045/D-046,
     * scripts/check_combinatorial_duplicates.php, 209 cles trouvees pour cette famille,
     * word_list_commencant_with_two_letters) -- ce lot avait ete applique au registre reel des
     * sa decouverte mais laissait ce builder generer des liens internes VIVANTS vers ces pages
     * devenues noindex,follow (violation R5). Voir docs/DECISIONS.md D-047/D-048.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'A:F:Z', 'A:G:W', 'A:W:Z', 'B:J:X', 'B:J:Z', 'C:J:K', 'C:J:Z', 'C:K:X',
        'C:K:Z', 'C:W:X', 'D:F:W', 'D:J:Z', 'D:K:X', 'D:W:Z', 'D:X:Z', 'E:G:K',
        'E:J:Z', 'E:K:Q', 'E:K:X', 'E:W:Y', 'F:J:X', 'F:J:Z', 'F:K:X', 'F:K:Z',
        'F:P:Z', 'F:Q:X', 'F:Q:Z', 'F:V:Z', 'F:W:Z', 'F:X:Z', 'G:B:X', 'G:D:X',
        'G:H:X', 'G:J:Z', 'G:K:X', 'G:K:Y', 'G:P:X', 'G:W:X', 'H:J:Z', 'H:K:X',
        'I:B:K', 'I:D:J', 'I:G:W', 'I:J:X', 'I:J:Z', 'I:K:V', 'I:K:Z', 'I:P:Z',
        'I:Q:Z', 'I:V:Z', 'I:W:Y', 'I:W:Z', 'J:B:Z', 'J:D:F', 'J:D:W', 'J:D:X',
        'J:D:Z', 'J:K:X', 'J:K:Z', 'J:M:W', 'J:O:W', 'J:R:W', 'J:V:X', 'J:X:Y',
        'J:X:Z', 'K:B:F', 'K:D:V', 'K:D:X', 'K:F:Q', 'K:F:X', 'K:G:V', 'K:H:X',
        'K:W:Y', 'K:X:Y', 'K:X:Z', 'K:Y:Z', 'L:J:P', 'L:J:Z', 'L:K:Z', 'L:X:Z',
        'M:B:J', 'M:F:J', 'M:F:Z', 'M:J:X', 'M:J:Z', 'M:K:X', 'M:W:X', 'N:B:X',
        'N:D:X', 'N:F:W', 'N:F:X', 'N:G:X', 'N:J:M', 'N:J:Z', 'N:K:P', 'N:K:X',
        'N:U:W', 'N:W:Y', 'N:X:Y', 'N:X:Z', 'N:Y:Z', 'O:C:W', 'O:F:W', 'O:F:Z',
        'O:J:W', 'O:J:X', 'O:J:Z', 'O:V:W', 'P:F:W', 'P:J:X', 'P:J:Z', 'P:K:X',
        'P:K:Z', 'P:M:W', 'P:X:Z', 'Q:B:Z', 'Q:C:X', 'Q:G:H', 'Q:G:V', 'Q:G:X',
        'Q:G:Z', 'Q:I:W', 'Q:I:X', 'Q:J:X', 'Q:K:P', 'Q:L:X', 'Q:M:X', 'Q:N:W',
        'Q:N:X', 'Q:P:X', 'Q:P:Z', 'Q:R:W', 'Q:S:W', 'Q:T:X', 'Q:W:Z', 'Q:X:Z',
        'Q:Y:Z', 'R:K:X', 'S:J:Z', 'S:X:Z', 'T:C:W', 'T:J:X', 'T:J:Y', 'T:W:Z',
        'U:B:D', 'U:B:K', 'U:D:Z', 'U:F:X', 'U:G:K', 'U:G:V', 'U:G:W', 'U:G:Z',
        'U:M:Z', 'U:P:V', 'U:P:X', 'U:P:Z', 'U:Q:Z', 'U:V:X', 'U:V:Z', 'U:X:Y',
        'U:Y:Z', 'V:B:K', 'V:D:X', 'V:F:Z', 'V:G:J', 'V:H:Z', 'V:J:Y', 'V:J:Z',
        'V:K:Y', 'V:X:Y', 'W:A:X', 'W:B:P', 'W:C:X', 'W:C:Y', 'W:D:V', 'W:F:Q',
        'W:F:V', 'W:F:Z', 'W:L:X', 'W:P:Z', 'W:Q:V', 'W:V:Z', 'X:F:S', 'X:L:Z',
        'Y:A:X', 'Y:B:D', 'Y:C:X', 'Y:D:G', 'Y:D:X', 'Y:E:X', 'Y:F:K', 'Y:G:J',
        'Y:J:S', 'Y:L:Z', 'Y:O:X', 'Y:P:X', 'Y:Q:V', 'Z:B:K', 'Z:B:X', 'Z:C:X',
        'Z:D:X', 'Z:F:W', 'Z:G:X', 'Z:H:J', 'Z:H:K', 'Z:J:S', 'Z:K:Q', 'Z:R:X',
        'Z:W:Y',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $prefix, string $letter): PrefixAvecTwoLettersLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'start_with_pair'"
            . ' AND (list_key LIKE ? OR list_key LIKE ?)'
        );
        $statement->execute([$prefix . ':' . $letter . ':%', $prefix . ':%:' . $letter]);

        self::$duplicateParentKeySet ??= array_flip(self::DUPLICATE_PARENT_KEYS);
        self::$siblingDuplicateKeySet ??= array_flip(self::SIBLING_DUPLICATE_KEYS);
        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);

        $links = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];

            if (
                isset(self::$duplicateParentKeySet[$key])
                || isset(self::$siblingDuplicateKeySet[$key])
                || isset(self::$externalDuplicateKeySet[$key])
            ) {
                continue;
            }

            $parts = explode(':', $key, 3);
            $partner = $parts[1] === $letter ? $parts[2] : $parts[1];
            $count = (int) $row['count'];

            $path = 'commencant/' . strtolower($prefix) . '/avec/' . strtolower($letter) . '/' . strtolower($partner);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $partner, 'url' => $url, 'count' => $count];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new PrefixAvecTwoLettersLinks(links: $links, queryCount: 1);
    }
}
