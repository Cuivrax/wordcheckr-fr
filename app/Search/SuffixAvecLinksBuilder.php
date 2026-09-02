<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\SuffixAvecLinks depuis list_counts (list_type 'end_with'), symetrique de
 * App\Search\PrefixAvecLinksBuilder cote suffixe (Family::WORD_LIST_TERMINANT_WITH_LETTER,
 * famille entierement nouvelle, D-045) -- une seule requete triviale, aucun calcul sur `terms`
 * au runtime.
 *
 * list_key est toujours "{suffixe}:{lettre}" pour 'end_with' -- une seule direction de lecture
 * necessaire (la page source est toujours /mots/terminant/{X}).
 *
 * Les combinaisons degenerees (lettre "avec" = suffixe, D-032) sont deja absentes de
 * list_counts -- exclues AU PRECALCUL (scripts/build_explore_hub_counts.php), meme discipline
 * que 'start_with'/App\Search\PrefixAvecLinksBuilder.
 */
final class SuffixAvecLinksBuilder
{
    // CORRECTIF PERF (2026-09-01, meme pattern que AvecFourLettersLinksBuilder) : in_array($key,
    // self::X_KEYS, true) sur ces trois constantes est un parcours lineaire relance a CHAQUE
    // ligne list_counts examinee dans build(). Tables de hachage calculees UNE FOIS par process
    // (cache statique), lookups O(1) au lieu de O(n) -- aucun changement de contenu.
    private static ?array $duplicateContentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $externalDuplicateKeySet = null;

    /**
     * Doublons de CONTENU avec la page PARENTE (D-045, meme methodologie que
     * App\Search\PrefixAvecLinksBuilder::DUPLICATE_CONTENT_KEYS) -- voir docs/DECISIONS.md
     * D-045.
     *
     * @var list<string>
     */
    private const DUPLICATE_CONTENT_KEYS = [];

    /**
     * Doublons de contenu entre pages SOEURS du meme suffixe (D-045, meme methodologie que
     * App\Search\PrefixAvecLinksBuilder::SIBLING_DUPLICATE_KEYS) -- voir docs/DECISIONS.md
     * D-045.
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE (D-045, meme discipline D-041) --
     * remplie par D-047 (balayage generique complet post-D-045/D-046,
     * scripts/check_combinatorial_duplicates.php, 23 cles trouvees pour cette famille,
     * word_list_terminant_with_letter) -- ce lot avait ete applique au registre reel des sa
     * decouverte mais laissait ce builder generer des liens internes VIVANTS vers ces pages
     * devenues noindex,follow (violation R5, confirmee en direct via HTTP avant correctif :
     * /mots/terminant/q liait vers /mots/terminant/q/avec/b, noindex depuis D-047). Voir
     * docs/DECISIONS.md D-047/D-048.
     *
     * PAS REVALIDEE par le passage a 844 961 termes (D-051/D-052, 2026-09-02) : contrairement a
     * App\Search\PrefixAvecLinksBuilder::EXTERNAL_DUPLICATE_KEYS (4 cles, adversaires nommes
     * individuellement dans son propre docblock, verifiables sans registre), ces 23 cles n'ont
     * jamais eu leur adversaire exact consigne ici -- seul le resultat du balayage generique
     * (scripts/check_combinatorial_duplicates.php contre storage/seo_fr.sqlite) l'identifie. Cette
     * liste depend donc du registre SEO complet, reconstruit et revalide separement (hors
     * perimetre data-engine a ce stade). Choix delibere (meme raisonnement que partout ailleurs sur
     * cette serie de correctifs) : laisser une cle DEDANS a tort coute au plus quelques liens
     * manques, en retirer une a tort reintroduirait un lien vivant vers une page potentiellement
     * noindex (violation R5) -- liste INCHANGEE ici, a revalider par un balayage generique complet
     * des que le registre sera reconstruit.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'B:F', 'B:Q', 'B:V', 'B:Z', 'C:W', 'J:C', 'J:F', 'J:H',
        'J:T', 'J:Z', 'P:J', 'Q:B', 'Q:F', 'Q:Z', 'U:W', 'V:G',
        'V:P', 'V:Y', 'W:B', 'W:F', 'W:P', 'W:Q', 'W:V',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $suffix): SuffixAvecLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'end_with' AND list_key LIKE ?"
        );
        $statement->execute([$suffix . ':%']);

        $parentUrl = WordListFilters::fromPath('terminant/' . strtolower($suffix))?->canonicalUrl();

        self::$duplicateContentKeySet ??= array_flip(self::DUPLICATE_CONTENT_KEYS);
        self::$siblingDuplicateKeySet ??= array_flip(self::SIBLING_DUPLICATE_KEYS);
        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);

        $links = [];

        foreach ($statement as $row) {
            [, $letter] = explode(':', (string) $row['list_key'], 2);

            $path = 'terminant/' . strtolower($suffix) . '/avec/' . strtolower($letter);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url === null || $url === $parentUrl) {
                continue;
            }

            $key = strtoupper($suffix) . ':' . strtoupper($letter);

            if (
                isset(self::$duplicateContentKeySet[$key])
                || isset(self::$siblingDuplicateKeySet[$key])
                || isset(self::$externalDuplicateKeySet[$key])
            ) {
                continue;
            }

            $links[] = ['letter' => $letter, 'url' => $url, 'count' => (int) $row['count']];
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new SuffixAvecLinks(links: $links, queryCount: 1);
    }
}
