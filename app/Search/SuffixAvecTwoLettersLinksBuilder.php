<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\SuffixAvecTwoLettersLinks depuis list_counts (list_type
 * 'end_with_pair'), symetrique de App\Search\PrefixAvecTwoLettersLinksBuilder cote suffixe
 * (Family::WORD_LIST_TERMINANT_WITH_TWO_LETTERS, D-045) -- une seule requete triviale.
 *
 * list_key est toujours "{suffixe}:{lettre1}:{lettre2}" avec lettre1 < lettre2
 * ALPHABETIQUEMENT. Meme OR a deux motifs que App\Search\AvecTwoLettersLinksBuilder.
 */
final class SuffixAvecTwoLettersLinksBuilder
{
    /**
     * Doublons de contenu avec la page PARENTE palier 1 (D-045). Calculee PROGRAMMATIQUEMENT
     * (comparaison directe list_counts, chargement en memoire par suffixe), 82 cles trouvees sur
     * les 6 240 candidats reels -- voir docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const DUPLICATE_PARENT_KEYS = [
        'B:A:Q', 'B:A:Y', 'B:C:V', 'B:D:V', 'B:E:V', 'B:I:Q',
        'B:I:V', 'B:L:V', 'B:L:Y', 'B:M:Y', 'B:N:Q', 'B:O:V',
        'B:O:Y', 'B:R:Y', 'B:U:V', 'C:A:X', 'C:E:X', 'C:I:X',
        'C:N:X', 'C:O:W', 'F:I:J', 'J:A:H', 'J:A:K', 'J:A:M',
        'J:A:T', 'J:B:D', 'J:B:O', 'J:B:Z', 'J:C:D', 'J:C:I',
        'J:D:Z', 'J:E:F', 'J:E:Z', 'J:F:U', 'J:K:T', 'J:O:T',
        'J:O:Z', 'J:U:Z', 'K:Q:U', 'L:Q:U', 'O:Q:U', 'P:E:Q',
        'Q:A:F', 'Q:A:P', 'Q:B:C', 'Q:C:O', 'Q:C:R', 'Q:D:Z',
        'Q:E:H', 'Q:E:T', 'Q:E:Y', 'Q:E:Z', 'Q:H:T', 'Q:I:V',
        'Q:I:Z', 'Q:L:Y', 'Q:N:P', 'Q:N:Z', 'V:A:H', 'V:G:T',
        'V:H:O', 'V:M:O', 'V:O:Y', 'V:U:Y', 'W:A:D', 'W:A:K',
        'W:A:Q', 'W:A:U', 'W:E:V', 'W:F:L', 'W:F:O', 'W:I:V',
        'W:N:V', 'W:Q:S', 'W:Q:U', 'W:R:V', 'W:T:V', 'X:J:U',
        'X:Q:U', 'X:U:Z', 'Z:E:X', 'Z:E:Y',
    ];

    /**
     * Doublons de contenu entre pages SOEURS du meme suffixe (D-045) : regroupement par
     * (suffixe, count), verification par empreinte. 283 cles trouvees (perdantes, canonicalisees
     * vers la cle gagnante la plus petite alphabetiquement) -- voir docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [
        'B:D:K', 'B:D:W', 'B:F:L', 'B:F:M', 'B:F:N', 'B:F:S',
        'B:F:U', 'B:G:L', 'B:G:M', 'B:G:O', 'B:G:R', 'B:G:T',
        'B:H:K', 'B:H:N', 'B:H:P', 'B:H:Y', 'B:I:J', 'B:I:Y',
        'B:J:M', 'B:K:R', 'B:K:T', 'B:K:U', 'B:K:W', 'B:N:W',
        'B:P:Y', 'B:R:W', 'B:R:Z', 'B:S:W', 'B:T:W', 'B:T:Y',
        'B:U:W', 'B:U:Y', 'C:D:W', 'C:E:Q', 'C:E:W', 'C:G:X',
        'C:I:J', 'C:I:W', 'C:K:P', 'C:K:S', 'C:K:W', 'C:L:W',
        'C:O:X', 'C:P:Q', 'C:Q:R', 'C:Q:S', 'C:Q:T', 'C:Q:U',
        'C:R:W', 'C:S:X', 'C:T:X', 'C:T:Z', 'C:V:Y', 'C:Y:Z',
        'D:H:W', 'D:I:X', 'D:P:W', 'D:U:X', 'F:K:L', 'F:N:W',
        'F:N:Z', 'F:P:Q', 'F:S:W', 'F:T:Y', 'F:U:Z', 'F:X:Y',
        'G:K:Q', 'G:K:Z', 'G:L:Z', 'G:M:X', 'G:M:Y', 'G:N:Q',
        'G:N:X', 'G:O:Q', 'G:O:X', 'G:P:X', 'G:S:X', 'H:G:Z',
        'H:J:L', 'H:J:P', 'H:J:R', 'H:J:S', 'H:J:T', 'H:J:V',
        'H:P:Q', 'H:P:V', 'H:Q:U', 'H:R:Z', 'H:Y:Z', 'J:A:R',
        'J:A:U', 'J:B:U', 'J:D:M', 'J:D:N', 'J:E:O', 'J:I:O',
        'J:K:M', 'J:K:N', 'J:K:O', 'J:K:R', 'J:K:U', 'J:M:N',
        'J:M:R', 'J:M:U', 'J:N:O', 'J:N:R', 'J:N:U', 'J:O:R',
        'J:O:U', 'J:R:U', 'K:G:S', 'K:H:V', 'K:J:L', 'K:Q:R',
        'K:R:Z', 'K:W:Z', 'L:N:W', 'L:U:W', 'M:E:W', 'M:K:V',
        'M:Q:Y', 'M:S:W', 'M:S:Z', 'O:P:W', 'P:D:V', 'P:F:S',
        'P:F:U', 'P:H:Y', 'P:I:Q', 'P:I:Y', 'P:J:K', 'P:J:O',
        'P:K:N', 'P:K:Q', 'P:K:Y', 'P:L:V', 'P:L:Y', 'P:M:V',
        'P:M:Y', 'P:N:Y', 'P:Q:S', 'P:Q:T', 'P:Q:U', 'P:T:W',
        'Q:A:T', 'Q:B:N', 'Q:B:P', 'Q:B:T', 'Q:B:Y', 'Q:C:H',
        'Q:C:L', 'Q:C:P', 'Q:C:Y', 'Q:D:I', 'Q:D:O', 'Q:D:R',
        'Q:D:V', 'Q:E:M', 'Q:E:P', 'Q:E:U', 'Q:E:V', 'Q:H:O',
        'Q:L:N', 'Q:M:P', 'Q:M:S', 'Q:M:U', 'Q:M:V', 'Q:N:T',
        'Q:N:V', 'Q:N:Y', 'Q:O:P', 'Q:O:R', 'Q:O:S', 'Q:O:V',
        'Q:P:S', 'Q:P:U', 'Q:R:S', 'Q:R:T', 'Q:R:U', 'Q:S:T',
        'Q:S:U', 'Q:U:V', 'Q:U:Y', 'U:I:X', 'U:N:W', 'U:P:W',
        'U:S:W', 'U:T:X', 'U:W:Z', 'V:A:L', 'V:C:K', 'V:C:L',
        'V:E:S', 'V:E:T', 'V:E:U', 'V:H:I', 'V:H:K', 'V:H:L',
        'V:H:M', 'V:H:N', 'V:I:M', 'V:I:T', 'V:K:L', 'V:K:N',
        'V:K:U', 'V:L:M', 'V:L:N', 'V:L:O', 'V:L:T', 'V:M:S',
        'V:M:T', 'V:N:O', 'V:N:U', 'V:O:T', 'V:R:S', 'V:R:U',
        'V:S:U', 'W:B:G', 'W:B:L', 'W:B:N', 'W:B:O', 'W:B:U',
        'W:C:E', 'W:C:F', 'W:C:K', 'W:D:S', 'W:E:L', 'W:E:N',
        'W:E:P', 'W:E:R', 'W:E:S', 'W:E:T', 'W:F:H', 'W:F:S',
        'W:G:L', 'W:G:N', 'W:G:T', 'W:G:U', 'W:H:I', 'W:H:M',
        'W:H:P', 'W:H:R', 'W:I:K', 'W:I:L', 'W:I:N', 'W:I:P',
        'W:I:S', 'W:I:T', 'W:K:L', 'W:K:N', 'W:K:O', 'W:K:R',
        'W:K:S', 'W:L:M', 'W:L:N', 'W:L:P', 'W:M:O', 'W:M:R',
        'W:M:S', 'W:N:R', 'W:N:U', 'W:O:P', 'W:O:T', 'W:O:U',
        'W:P:S', 'W:R:S', 'W:R:T', 'W:S:T', 'X:H:W', 'X:P:W',
        'X:R:W', 'Y:F:O', 'Y:I:Q', 'Y:I:Z', 'Y:J:W', 'Y:K:W',
        'Y:N:Q', 'Y:P:W', 'Y:P:Z', 'Y:Q:W', 'Y:S:Z', 'Y:U:Z',
        'Y:V:Z',
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

    public function build(string $suffix, string $letter): SuffixAvecTwoLettersLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'end_with_pair'"
            . ' AND (list_key LIKE ? OR list_key LIKE ?)'
        );
        $statement->execute([$suffix . ':' . $letter . ':%', $suffix . ':%:' . $letter]);

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

            $path = 'terminant/' . strtolower($suffix) . '/avec/' . strtolower($letter) . '/' . strtolower($partner);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $partner, 'url' => $url, 'count' => $count];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new SuffixAvecTwoLettersLinks(links: $links, queryCount: 1);
    }
}
