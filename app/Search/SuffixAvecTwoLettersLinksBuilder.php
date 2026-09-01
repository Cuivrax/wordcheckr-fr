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
    // CORRECTIF PERF (2026-09-01, meme pattern que AvecFourLettersLinksBuilder) : in_array($key,
    // self::X_KEYS, true) sur ces trois constantes est un parcours lineaire relance a CHAQUE
    // ligne list_counts examinee dans build(). Tables de hachage calculees UNE FOIS par process
    // (cache statique), lookups O(1) au lieu de O(n) -- aucun changement de contenu.
    private static ?array $duplicateParentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $externalDuplicateKeySet = null;

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
     * remplie par D-047 (balayage generique complet post-D-045/D-046,
     * scripts/check_combinatorial_duplicates.php, 483 cles trouvees pour cette famille,
     * word_list_terminant_with_two_letters) -- ce lot avait ete applique au registre reel des
     * sa decouverte mais laissait ce builder generer des liens internes VIVANTS vers ces pages
     * devenues noindex,follow (violation R5). Voir docs/DECISIONS.md D-047/D-048.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'A:J:W', 'B:A:F', 'B:A:G', 'B:A:J', 'B:A:W', 'B:A:Z', 'B:C:F', 'B:C:J',
        'B:C:K', 'B:C:S', 'B:C:Y', 'B:D:G', 'B:D:H', 'B:D:J', 'B:D:S', 'B:E:W',
        'B:E:Y', 'B:E:Z', 'B:F:H', 'B:F:O', 'B:G:N', 'B:H:J', 'B:I:W', 'B:I:Z',
        'B:J:O', 'B:J:R', 'B:K:M', 'B:K:N', 'B:K:S', 'B:M:Z', 'B:N:P', 'B:N:R',
        'B:O:Z', 'B:U:Z', 'C:B:K', 'C:B:Q', 'C:B:V', 'C:B:W', 'C:B:X', 'C:D:Q',
        'C:D:V', 'C:D:X', 'C:E:K', 'C:F:G', 'C:F:H', 'C:F:J', 'C:F:K', 'C:F:M',
        'C:F:Q', 'C:F:V', 'C:H:V', 'C:H:W', 'C:I:Q', 'C:J:K', 'C:J:L', 'C:J:R',
        'C:K:N', 'C:K:U', 'C:K:Y', 'C:M:V', 'C:P:V', 'C:P:Z', 'C:S:Z', 'C:U:Y',
        'D:C:K', 'D:C:W', 'D:F:J', 'D:F:Q', 'D:F:Z', 'D:H:X', 'D:J:Q', 'D:J:W',
        'D:K:M', 'D:K:P', 'D:K:S', 'D:K:V', 'D:K:W', 'D:M:V', 'D:M:X', 'D:P:Q',
        'D:P:Z', 'D:S:W', 'D:S:X', 'E:J:W', 'F:B:Q', 'F:B:X', 'F:B:Z', 'F:D:K',
        'F:D:Q', 'F:D:W', 'F:G:K', 'F:G:X', 'F:H:K', 'F:I:W', 'F:I:Z', 'F:J:M',
        'F:K:N', 'F:K:P', 'F:K:T', 'F:K:Y', 'F:L:W', 'F:O:W', 'F:O:Z', 'F:P:W',
        'F:Q:R', 'F:Q:W', 'F:Q:Z', 'F:R:W', 'F:T:W', 'F:U:W', 'F:U:Y', 'G:A:Z',
        'G:B:V', 'G:B:Z', 'G:C:Z', 'G:D:V', 'G:F:P', 'G:F:X', 'G:F:Y', 'G:H:Q',
        'G:H:X', 'G:H:Y', 'G:H:Z', 'G:J:K', 'G:J:P', 'G:J:T', 'G:J:U', 'G:K:W',
        'G:K:X', 'G:M:Z', 'G:N:Z', 'G:O:Z', 'G:P:W', 'G:P:Y', 'G:P:Z', 'G:Q:S',
        'G:S:Z', 'G:U:Z', 'G:W:Y', 'G:W:Z', 'H:A:J', 'H:B:D', 'H:B:F', 'H:B:Y',
        'H:B:Z', 'H:C:G', 'H:C:J', 'H:C:Q', 'H:D:F', 'H:D:K', 'H:D:Q', 'H:D:U',
        'H:D:Y', 'H:E:W', 'H:F:G', 'H:F:Q', 'H:F:R', 'H:F:Y', 'H:G:M', 'H:G:P',
        'H:G:V', 'H:G:W', 'H:G:Y', 'H:J:M', 'H:K:P', 'H:K:W', 'H:K:Y', 'H:L:W',
        'H:M:V', 'H:N:V', 'H:N:Z', 'H:O:Q', 'H:P:Z', 'H:Q:S', 'H:R:W', 'H:S:Z',
        'H:U:W', 'H:W:Y', 'I:J:W', 'I:M:W', 'I:Q:W', 'J:A:N', 'J:A:O', 'J:B:E',
        'J:B:R', 'J:D:H', 'J:D:I', 'J:D:K', 'J:D:O', 'J:D:U', 'J:I:N', 'K:B:Q',
        'K:B:V', 'K:C:Q', 'K:D:G', 'K:D:L', 'K:D:W', 'K:D:Y', 'K:D:Z', 'K:E:J',
        'K:F:P', 'K:F:Z', 'K:G:M', 'K:H:Y', 'K:I:W', 'K:J:M', 'K:J:P', 'K:J:Y',
        'K:M:V', 'K:P:Q', 'K:P:Z', 'K:S:V', 'K:S:W', 'K:T:Z', 'K:U:V', 'K:U:W',
        'L:C:W', 'L:F:J', 'L:F:Q', 'L:F:W', 'L:F:Z', 'L:J:K', 'L:J:S', 'L:J:W',
        'L:J:Y', 'L:K:W', 'L:K:X', 'L:Q:V', 'L:Q:Z', 'L:S:W', 'L:T:W', 'L:V:X',
        'L:W:X', 'L:W:Y', 'M:B:J', 'M:B:P', 'M:B:W', 'M:C:Q', 'M:C:X', 'M:D:K',
        'M:D:W', 'M:F:G', 'M:F:P', 'M:F:W', 'M:F:X', 'M:G:J', 'M:G:Q', 'M:G:W',
        'M:G:Z', 'M:H:J', 'M:H:V', 'M:H:W', 'M:I:J', 'M:J:S', 'M:K:N', 'M:K:T',
        'M:K:W', 'M:K:Z', 'M:P:W', 'M:Q:S', 'M:Q:V', 'M:S:X', 'M:V:X', 'M:Y:Z',
        'N:J:W', 'N:K:X', 'N:V:W', 'O:B:W', 'O:B:Y', 'O:D:X', 'O:F:G', 'O:F:H',
        'O:F:K', 'O:G:Q', 'O:H:V', 'O:H:X', 'O:J:T', 'O:J:Z', 'O:K:Q', 'O:L:W',
        'O:Q:V', 'O:Q:X', 'O:R:W', 'O:V:X', 'O:V:Y', 'O:Y:Z', 'P:A:J', 'P:A:V',
        'P:A:Y', 'P:B:C', 'P:B:D', 'P:B:H', 'P:B:L', 'P:B:N', 'P:B:Z', 'P:C:Q',
        'P:C:V', 'P:D:G', 'P:D:K', 'P:D:T', 'P:D:Y', 'P:E:J', 'P:E:V', 'P:E:Y',
        'P:F:H', 'P:F:N', 'P:G:M', 'P:G:T', 'P:G:Y', 'P:H:J', 'P:I:V', 'P:I:Z',
        'P:J:L', 'P:J:M', 'P:J:U', 'P:K:W', 'P:M:U', 'P:N:W', 'P:O:V', 'P:R:V',
        'P:R:W', 'P:S:Y', 'P:U:V', 'P:U:Y', 'P:U:Z', 'Q:A:B', 'Q:A:R', 'Q:A:V',
        'Q:A:Y', 'Q:B:E', 'Q:B:H', 'Q:B:I', 'Q:B:L', 'Q:B:M', 'Q:B:O', 'Q:B:S',
        'Q:B:U', 'Q:C:V', 'Q:D:S', 'Q:H:M', 'Q:I:M', 'Q:I:P', 'Q:I:R', 'Q:L:M',
        'Q:L:S', 'Q:M:R', 'T:Q:W', 'T:W:Y', 'T:W:Z', 'U:A:W', 'U:A:X', 'U:B:Q',
        'U:C:W', 'U:D:Q', 'U:F:J', 'U:F:V', 'U:G:W', 'U:H:W', 'U:J:K', 'U:J:L',
        'U:J:Q', 'U:J:V', 'U:J:Y', 'U:K:V', 'U:K:W', 'U:K:Z', 'U:L:W', 'U:L:X',
        'U:N:X', 'U:O:W', 'U:P:Z', 'U:S:Z', 'U:V:Y', 'U:Y:Z', 'V:A:D', 'V:A:K',
        'V:A:M', 'V:A:O', 'V:A:T', 'V:C:H', 'V:C:S', 'V:C:U', 'V:D:E', 'V:D:I',
        'V:D:R', 'V:E:K', 'V:E:M', 'V:E:R', 'V:I:K', 'V:I:U', 'V:K:O', 'V:K:R',
        'V:L:S', 'V:R:T', 'W:A:B', 'W:A:E', 'W:A:F', 'W:A:M', 'W:A:P', 'W:B:M',
        'W:C:I', 'W:C:M', 'W:D:G', 'W:D:K', 'W:D:O', 'W:D:T', 'W:D:U', 'W:E:H',
        'W:E:I', 'W:H:T', 'W:L:R', 'W:N:S', 'W:S:U', 'W:T:U', 'X:A:W', 'X:E:W',
        'X:F:J', 'X:F:V', 'X:G:Q', 'X:I:W', 'X:J:K', 'X:J:Q', 'X:K:P', 'X:K:S',
        'X:K:V', 'X:M:W', 'X:O:W', 'X:Q:Z', 'X:S:Z', 'X:V:Z', 'Y:B:F', 'Y:B:J',
        'Y:B:P', 'Y:B:V', 'Y:B:Z', 'Y:C:D', 'Y:C:G', 'Y:C:J', 'Y:C:Q', 'Y:C:W',
        'Y:C:Z', 'Y:D:F', 'Y:D:G', 'Y:D:W', 'Y:D:Z', 'Y:E:J', 'Y:E:Q', 'Y:F:H',
        'Y:F:I', 'Y:F:K', 'Y:F:L', 'Y:F:M', 'Y:F:N', 'Y:F:P', 'Y:F:S', 'Y:F:T',
        'Y:F:U', 'Y:F:W', 'Y:G:P', 'Y:G:W', 'Y:G:Z', 'Y:H:W', 'Y:H:X', 'Y:J:K',
        'Y:J:N', 'Y:J:R', 'Y:J:S', 'Y:J:T', 'Y:J:U', 'Y:J:Z', 'Y:K:Z', 'Y:M:W',
        'Y:M:X', 'Y:N:X', 'Y:N:Z', 'Y:P:Q', 'Y:P:V', 'Y:P:X', 'Y:Q:S', 'Y:Q:T',
        'Y:W:X', 'Z:M:W', 'Z:Q:W',
    ];

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
