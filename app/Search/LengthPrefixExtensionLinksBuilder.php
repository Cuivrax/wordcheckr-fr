<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\LengthPrefixExtensionLinks depuis list_counts (list_type
 * 'length_prefix2'), meme principe que App\Search\PrefixExtensionLinksBuilder (variante SANS
 * longueur) -- une seule requete triviale, aucun GROUP BY sur `terms` au runtime.
 *
 * list_key est toujours "{longueur}:{prefixe 2 lettres}" pour 'length_prefix2' -- le filtre
 * `list_key LIKE '{longueur}:{lettre}_'` (joker simple `_`, pas `%`) matche exactement les
 * extensions d'UNE lettre a une longueur donnee.
 */
final class LengthPrefixExtensionLinksBuilder
{
    /**
     * Doublons de contenu (D-044, verification EXTERNE faite AVANT application du lot SEO --
     * scripts/seo-batches/length-prefix-suffix-2-2026-08-31.php, meme methodologie que D-039/
     * D-040/D-041 : longueur seule, prefixe/suffixe SANS longueur par empreinte d'affixe commun,
     * parent direct du palier 1, avec-single/avec-two-letters). 223 cles "{longueur}:{2 lettres}"
     * dont la page cible est en realite un doublon de contenu exact d'une page deja indexee
     * (noindex,follow + canonical vers la forme gagnante dans le registre) -- jamais rendues ici
     * pour ne jamais lier en interne vers une page non indexee, meme precedent que
     * App\Search\SuffixExtensionLinksBuilder::EXTERNAL_DUPLICATE_SUFFIXES (D-041).
     *
     * Liste figee : valable pour l'etat actuel de storage/dictionary_fr.sqlite. Une
     * reconstruction future de la base devra revalider cette liste.
     *
     * PAS REVALIDEE pour D-051/D-052 (base passee a 844 961 termes, +6 781 formes kaikki) :
     * necessite le registre SEO complet (storage/seo_fr.sqlite) et
     * scripts/check_combinatorial_duplicates.php (D-041), hors perimetre data-engine pour une
     * revalidation limitee a dictionary_fr.sqlite -- laissee inchangee par prudence (risque
     * residuel documente dans le rapport de revalidation D-053).
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        '11:CS', '11:KW', '11:ZL', '12:DN', '12:KW', '12:QU', '12:YI', '13:BH',
        '13:QU', '14:AA', '14:QU', '15:KH', '15:QU', '2:CD', '2:CV', '2:FM',
        '2:GD', '2:LT', '2:MC', '2:MR', '2:NB', '2:PB', '2:PD', '2:PK',
        '2:PM', '2:PV', '2:QI', '2:QQ', '2:QU', '2:SR', '2:UA', '2:VS',
        '2:WU', '2:YI', '2:ZA', '3:AJ', '3:BC', '3:BG', '3:BM', '3:BN',
        '3:BX', '3:BZ', '3:CG', '3:DB', '3:DD', '3:DP', '3:DS', '3:DT',
        '3:DV', '3:ED', '3:EQ', '3:EX', '3:FT', '3:GD', '3:GS', '3:HL',
        '3:HS', '3:HT', '3:IF', '3:IT', '3:IU', '3:JI', '3:JR', '3:KH',
        '3:KP', '3:KR', '3:KS', '3:KU', '3:KW', '3:KY', '3:LS', '3:MC',
        '3:MD', '3:MG', '3:ML', '3:MR', '3:MS', '3:MT', '3:ND', '3:OG',
        '3:PC', '3:PF', '3:PM', '3:QC', '3:QO', '3:QU', '3:RD', '3:RF',
        '3:RG', '3:RP', '3:SD', '3:SG', '3:SL', '3:SM', '3:SQ', '3:SS',
        '3:TG', '3:TJ', '3:TM', '3:TN', '3:TP', '3:TT', '3:TV', '3:UD',
        '3:UG', '3:VP', '3:VR', '3:WI', '3:WU', '3:XE', '3:XV', '3:XX',
        '3:YI', '4:AA', '4:AJ', '4:AZ', '4:BC', '4:BG', '4:BT', '4:BZ',
        '4:CC', '4:CD', '4:CF', '4:CQ', '4:CZ', '4:DG', '4:DL', '4:DP',
        '4:DT', '4:DZ', '4:EO', '4:FN', '4:FP', '4:FQ', '4:IY', '4:IZ',
        '4:JD', '4:JI', '4:JP', '4:KS', '4:KV', '4:LC', '4:LG', '4:MC',
        '4:MG', '4:ML', '4:MM', '4:ND', '4:NT', '4:OD', '4:OH', '4:PD',
        '4:PK', '4:PP', '4:PT', '4:PY', '4:QQ', '4:RS', '4:RT', '4:SB',
        '4:SY', '4:TZ', '4:UT', '4:VU', '4:WU', '4:XA', '4:XE', '4:YI',
        '4:ZB', '4:ZG', '4:ZH', '4:ZR', '4:ZY', '5:AA', '5:BH', '5:CC',
        '5:CM', '5:CQ', '5:DG', '5:DZ', '5:EO', '5:FD', '5:FQ', '5:HM',
        '5:JB', '5:MC', '5:NG', '5:OO', '5:RV', '5:TT', '5:UH', '5:XO',
        '5:ZL', '6:AA', '6:CT', '6:FD', '6:IK', '6:ML', '6:NG', '6:NM',
        '6:OJ', '6:QQ', '6:RM', '6:SR', '6:TL', '6:TN', '6:UD', '6:VT',
        '6:ZD', '6:ZN', '7:CS', '7:EK', '7:MG', '7:NG', '7:PF', '7:TX',
        '8:MC', '8:TJ', '8:WR', '9:KC', '9:ML', '9:OK', '9:UW',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(int $length, string $prefix): LengthPrefixExtensionLinks
    {
        if (strlen($prefix) !== 1) {
            return new LengthPrefixExtensionLinks(links: [], queryCount: 0);
        }

        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'length_prefix2' AND list_key LIKE ?"
        );
        $statement->execute([$length . ':' . $prefix . '_']);

        $links = [];

        foreach ($statement as $row) {
            $listKey = (string) $row['list_key'];

            if (in_array($listKey, self::EXTERNAL_DUPLICATE_KEYS, true)) {
                continue;
            }

            [, $extendedPrefix] = explode(':', $listKey, 2);

            $url = WordListFilters::fromPath(
                $length . '-lettres/commencant/' . strtolower($extendedPrefix)
            )?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['prefix' => $extendedPrefix, 'url' => $url, 'count' => (int) $row['count']];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['prefix'] <=> $b['prefix']);

        return new LengthPrefixExtensionLinks(links: $links, queryCount: 1);
    }
}
