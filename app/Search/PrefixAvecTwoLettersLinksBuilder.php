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
     * voir docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [];

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

        $links = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];

            if (
                in_array($key, self::DUPLICATE_PARENT_KEYS, true)
                || in_array($key, self::SIBLING_DUPLICATE_KEYS, true)
                || in_array($key, self::EXTERNAL_DUPLICATE_KEYS, true)
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
