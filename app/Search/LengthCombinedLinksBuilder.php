<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\LengthCombinedLinks depuis list_counts (list_type 'length_start_end',
 * D-027, voir reports/query-plans/length-combined-links.md) -- meme principe que
 * App\Search\LetterCombinedLinksBuilder (D-024) et App\Search\PositionLinksBuilder (D-023bis) :
 * une seule requete triviale, aucun GROUP BY sur `terms` au runtime.
 *
 * list_key est toujours "{longueur}:{debut}:{fin}" pour 'length_start_end'. Les deux sens de
 * lecture restent efficaces malgre le joker en tete cote "buildForEnd()" (`LIKE '{N}:%:{Y}'`) :
 * le prefixe litteral "{N}:" borne deja la recherche a une seule longueur (au plus 676 lignes,
 * 26 debuts x 26 fins), memes conditions de cout que le joker en tete deja accepte pour
 * App\Search\LetterCombinedLinksBuilder::buildForEnd() sur la table list_counts entiere (13 846
 * lignes au total, tous list_type confondus -- sans rapport avec le risque de SCAN sur `terms`,
 * 838 180 lignes, que ce projet interdit par ailleurs).
 *
 * Budget runtime : 1 requete SQLite par page (buildForStart() OU buildForEnd(), jamais les deux
 * sur la meme page -- une page ne peut jamais avoir a la fois "commencant" seul ET "terminant"
 * seul).
 */
final class LengthCombinedLinksBuilder
{
    // CORRECTIF PERF (2026-09-01, meme pattern que AvecFourLettersLinksBuilder) : in_array($key,
    // self::EXTERNAL_DUPLICATE_KEYS, true) est un parcours lineaire relance a CHAQUE ligne
    // list_counts examinee dans build() (et aussi referencee depuis LengthLinksBuilder). Table
    // de hachage calculee UNE FOIS par process (cache statique), lookup O(1) au lieu de O(n) --
    // aucun changement de contenu.
    private static ?array $externalDuplicateKeySet = null;

    /**
     * Doublons de contenu CROISÉS avec une famille EXTÉRIEURE à la variante longueur+commençant+
     * terminant (D-041, garde-fou structurel demandé par le constat C-4 du 4e audit consolidé,
     * docs/DECISIONS.md D-040) -- trouvés par le balayage GÉNÉRIQUE de tout le registre
     * (scripts/check_combinatorial_duplicates.php, balayage du 2026-08-21 : 1 656 groupes,
     * 2 089 pages en excès), pas une comparaison ciblée à une seule paire de familles.
     *
     * Clé au format exact du `list_key` 'length_start_end' ("{longueur}:{début}:{fin}", D-027),
     * comparée directement à la clé reconstruite ci-dessous dans build(). Ce même ensemble est
     * aussi utilisé par App\Search\LengthLinksBuilder (byStartEnd, qui cible la MÊME famille --
     * App\Seo\Family::WORD_LIST_COMBINED, variante avec longueur -- depuis une page source
     * différente, /mots/{N}-lettres) -- référencé depuis là-bas plutôt que dupliqué, une seule
     * source de vérité pour cette famille cible. Distinct de
     * App\Search\LengthLinksBuilder::DUPLICATE_START_END_KEYS (D-025/I-1, doublon avec la variante
     * SANS longueur du MÊME panier, 52 clés) : ici, le doublon est avec une AUTRE famille
     * (terminant/commençant multi-lettres, avec à N lettres, position...), jamais avec le panier
     * sans longueur lui-même.
     *
     * Règle de départage : App\Search\DuplicatePageResolver::resolveDuplicateWinner() -- une page
     * "{N}-lettres/commençant/{X}/terminant/{Y}" a TOUJOURS 3 composants. Perd face à tout
     * adversaire à 1 ou 2 composants (commençant/terminant multi-lettres seuls, avec à une lettre,
     * commençant+terminant sans longueur...), et perd aussi, à 3 composants égaux, face à
     * "position" (signature de rôles [longueur, commençant, terminant] précède [longueur,
     * position, position] -- "commençant" avant "position" dans l'ordre canonique, cette page
     * gagne dans CE cas précis) : cette liste ne contient donc AUCUN cas position-vs-combiné (voir
     * PositionLinksBuilder::EXTERNAL_DUPLICATE_KEYS, qui contient l'inverse : la variante position
     * qui perd face à CETTE famille). Recalculé indépendamment par échantillonnage direct contre
     * `terms` (voir le rapport AFTER de cette tâche) : 0 divergence.
     *
     * Liste figée : valable pour l'état actuel de storage/dictionary_fr.sqlite (838 180 termes,
     * inchangé depuis D-022). Une reconstruction future de la base devra revalider cette liste.
     *
     * COMPLÉTÉE PAR D-047 (2026-08-31, balayage générique post-D-045/D-046) : +195 clés
     * supplémentaires (extraites directement du lot storage/seo_fr.sqlite/word_list_combined déjà
     * appliqué au registre, variante AVEC longueur). Découverte tardive : ce lot avait été
     * appliqué au registre réel dès sa découverte mais laissait ce builder (et
     * App\Search\LengthLinksBuilder::byStartEnd, qui référence cette même constante) générer des
     * liens internes VIVANTS vers ces pages devenues noindex,follow (violation R5, confirmée en
     * direct via HTTP avant correctif : /mots/10-lettres liait vers
     * /mots/10-lettres/commencant/b/terminant/q, noindex depuis D-047). Voir
     * docs/DECISIONS.md D-047/D-048.
     *
     * @var list<string>
     */
    public const EXTERNAL_DUPLICATE_KEYS = [
        '10:B:K', '10:B:Q', '10:C:Y', '10:F:Y', '10:H:M', '10:I:K', '10:I:Q', '10:J:O',
        '10:J:P', '10:J:Y', '10:K:M', '10:K:Y', '10:M:Y', '10:O:P', '10:P:Y', '10:R:Y',
        '10:T:C', '10:W:F', '10:Y:O', '10:Y:U', '11:B:Q', '11:C:B', '11:C:O', '11:C:P',
        '11:E:C', '11:E:K', '11:J:C', '11:K:O', '11:K:V', '11:L:P', '11:M:C', '11:M:K',
        '11:M:U', '11:M:Y', '11:P:H', '11:R:K', '11:S:O', '11:S:Y', '11:T:M', '11:T:O',
        '11:T:Y', '12:B:K', '12:B:O', '12:C:Y', '12:D:K', '12:E:H', '12:E:Y', '12:J:G',
        '12:M:B', '12:P:P', '12:P:Y', '12:Y:I', '13:A:D', '13:A:O', '13:B:M', '13:C:H',
        '13:I:O', '13:R:U', '13:S:C', '13:S:K', '14:C:D', '14:M:D', '14:P:H', '14:P:O',
        '14:S:O', '14:W:G', '15:C:C', '15:K:N', '15:P:O', '15:Q:O', '15:R:C', '15:T:B',
        '15:T:M', '15:V:H', '2:A:A', '2:A:B', '2:A:C', '2:A:G', '2:A:H', '2:A:I',
        '2:A:K', '2:A:M', '2:A:N', '2:A:R', '2:A:S', '2:A:U', '2:A:V', '2:A:Y',
        '2:B:A', '2:B:D', '2:B:E', '2:B:G', '2:B:I', '2:B:M', '2:B:U', '2:B:Y',
        '2:C:A', '2:C:C', '2:C:D', '2:C:E', '2:C:H', '2:C:I', '2:C:U', '2:C:V',
        '2:D:A', '2:D:E', '2:D:J', '2:D:O', '2:D:U', '2:E:D', '2:E:H', '2:E:K',
        '2:E:N', '2:E:S', '2:E:T', '2:E:U', '2:E:V', '2:E:X', '2:F:A', '2:F:I',
        '2:F:M', '2:G:A', '2:G:D', '2:G:E', '2:G:O', '2:G:P', '2:H:A', '2:H:E',
        '2:H:I', '2:H:O', '2:H:T', '2:I:A', '2:I:F', '2:I:L', '2:I:N', '2:I:S',
        '2:J:A', '2:J:E', '2:J:I', '2:K:A', '2:K:O', '2:K:U', '2:L:A', '2:L:E',
        '2:L:I', '2:L:T', '2:L:U', '2:M:A', '2:M:C', '2:M:E', '2:M:I', '2:M:R',
        '2:M:U', '2:N:A', '2:N:B', '2:N:E', '2:N:I', '2:N:O', '2:N:U', '2:O:C',
        '2:O:H', '2:O:I', '2:O:K', '2:O:M', '2:O:N', '2:O:R', '2:O:S', '2:O:U',
        '2:P:A', '2:P:B', '2:P:C', '2:P:D', '2:P:E', '2:P:H', '2:P:I', '2:P:K',
        '2:P:M', '2:P:S', '2:P:U', '2:Q:I', '2:Q:U', '2:R:A', '2:R:B', '2:R:E',
        '2:R:I', '2:R:U', '2:R:Y', '2:S:A', '2:S:E', '2:S:F', '2:S:H', '2:S:I',
        '2:S:M', '2:S:R', '2:S:U', '2:T:A', '2:T:E', '2:T:I', '2:T:O', '2:T:U',
        '2:T:V', '2:U:A', '2:U:D', '2:U:N', '2:U:S', '2:U:T', '2:V:A', '2:V:E',
        '2:V:S', '2:V:U', '2:W:U', '2:X:E', '2:X:I', '2:Y:E', '2:Y:I', '2:Y:O',
        '2:Z:A', '3:A:K', '3:A:Y', '3:B:W', '3:C:H', '3:C:O', '3:C:Q', '3:C:X',
        '3:C:Y', '3:D:G', '3:D:J', '3:D:W', '3:E:C', '3:E:G', '3:E:P', '3:F:B',
        '3:F:G', '3:F:K', '3:F:P', '3:F:Y', '3:F:Z', '3:G:B', '3:G:O', '3:G:P',
        '3:I:C', '3:I:D', '3:I:G', '3:I:H', '3:I:I', '3:I:M', '3:J:D', '3:J:I',
        '3:J:P', '3:J:Z', '3:K:B', '3:K:D', '3:K:E', '3:K:L', '3:M:P', '3:N:B',
        '3:N:G', '3:N:K', '3:O:G', '3:O:H', '3:O:O', '3:P:D', '3:P:G', '3:Q:M',
        '3:Q:T', '3:S:B', '3:S:F', '3:S:W', '3:S:Y', '3:S:Z', '3:T:Z', '3:U:F',
        '3:U:L', '3:U:M', '3:V:D', '3:V:H', '3:V:M', '3:W:B', '3:W:D', '3:W:H',
        '3:W:I', '3:W:K', '3:W:S', '3:W:U', '3:W:X', '3:Y:E', '3:Z:D', '3:Z:K',
        '3:Z:Y', '4:A:D', '4:A:P', '4:A:V', '4:C:Q', '4:D:W', '4:E:F', '4:E:X',
        '4:F:F', '4:G:B', '4:G:X', '4:H:K', '4:H:M', '4:I:K', '4:I:X', '4:J:H',
        '4:J:X', '4:J:Y', '4:K:D', '4:K:H', '4:K:M', '4:K:P', '4:K:R', '4:K:U',
        '4:K:Y', '4:L:B', '4:L:L', '4:M:F', '4:N:B', '4:N:M', '4:N:X', '4:P:B',
        '4:P:Z', '4:Q:E', '4:Q:G', '4:Q:Y', '4:Q:Z', '4:R:B', '4:S:Z', '4:U:C',
        '4:U:F', '4:U:L', '4:U:Z', '4:V:F', '4:V:G', '4:V:K', '4:V:L', '4:W:F',
        '4:W:G', '4:W:M', '4:W:O', '4:W:U', '4:W:Z', '4:X:A', '4:Y:L', '4:Y:U',
        '4:Y:X', '4:Z:H', '4:Z:L', '4:Z:R', '5:A:G', '5:A:V', '5:A:Y', '5:B:P',
        '5:B:Q', '5:C:Q', '5:D:B', '5:E:P', '5:E:V', '5:E:Y', '5:H:B', '5:H:G',
        '5:I:H', '5:K:F', '5:K:G', '5:K:M', '5:K:P', '5:K:X', '5:L:M', '5:M:B',
        '5:N:G', '5:O:H', '5:O:K', '5:O:P', '5:O:X', '5:P:W', '5:Q:U', '5:S:W',
        '5:U:Z', '5:V:G', '5:V:Y', '5:W:X', '5:W:Y', '5:Y:D', '5:Y:M', '5:Y:U',
        '5:Y:Y', '6:A:B', '6:A:G', '6:A:H', '6:D:B', '6:E:H', '6:E:Y', '6:F:B',
        '6:G:F', '6:G:H', '6:G:P', '6:H:J', '6:H:P', '6:I:G', '6:J:G', '6:J:H',
        '6:K:C', '6:K:G', '6:K:V', '6:K:Y', '6:L:B', '6:L:K', '6:L:P', '6:M:B',
        '6:N:B', '6:N:H', '6:O:H', '6:O:O', '6:O:Y', '6:P:Y', '6:Q:G', '6:R:V',
        '6:S:P', '6:S:W', '6:T:P', '6:W:C', '6:W:F', '6:W:Y', '6:X:N', '6:Z:C',
        '6:Z:F', '6:Z:M', '7:A:Y', '7:B:B', '7:B:Y', '7:C:B', '7:D:B', '7:H:Y',
        '7:I:K', '7:I:Q', '7:J:C', '7:K:F', '7:K:J', '7:K:P', '7:K:Y', '7:M:P',
        '7:N:Y', '7:O:D', '7:O:Y', '7:Q:Y', '7:S:W', '7:V:Y', '7:W:F', '7:Y:D',
        '7:Y:K', '7:Z:M', '8:A:Y', '8:B:W', '8:D:P', '8:D:Y', '8:F:B', '8:G:K',
        '8:H:C', '8:H:O', '8:H:Y', '8:I:K', '8:J:C', '8:K:J', '8:M:B', '8:Q:M',
        '8:R:B', '8:S:B', '8:U:C', '8:V:K', '8:V:Y', '8:Y:Y', '8:Z:G', '8:Z:Y',
        '9:C:K', '9:C:W', '9:D:K', '9:G:K', '9:H:F', '9:I:Y', '9:K:H', '9:L:V',
        '9:N:H', '9:P:Y', '9:S:Y', '9:W:U', '9:W:X', '9:X:N', '9:Y:H',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** Pour une page /mots/{N}-lettres/commencant/{X} : liens vers .../commencant/{X}/terminant/{Y}. */
    public function buildForStart(int $length, string $startLetter): LengthCombinedLinks
    {
        return $this->build($length . ':' . $startLetter . ':%', $length, fromStart: true);
    }

    /** Pour une page /mots/{N}-lettres/terminant/{Y} : liens vers .../commencant/{X}/terminant/{Y}. */
    public function buildForEnd(int $length, string $endLetter): LengthCombinedLinks
    {
        return $this->build($length . ':%:' . $endLetter, $length, fromStart: false);
    }

    private function build(string $likePattern, int $length, bool $fromStart): LengthCombinedLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'length_start_end' AND list_key LIKE ?"
        );
        $statement->execute([$likePattern]);

        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);

        $links = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];

            if (isset(self::$externalDuplicateKeySet[$key])) {
                continue;
            }

            [, $start, $end] = explode(':', $key, 3);
            $other = $fromStart ? $end : $start;

            $url = WordListFilters::fromPath(
                $length . '-lettres/commencant/' . strtolower($start) . '/terminant/' . strtolower($end)
            )?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $other, 'url' => $url, 'count' => (int) $row['count']];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new LengthCombinedLinks(links: $links, queryCount: 1);
    }
}
