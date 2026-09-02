<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\LengthSuffixExtensionLinks depuis list_counts (list_type
 * 'length_suffix2'), symetrique de App\Search\LengthPrefixExtensionLinksBuilder.
 *
 * L'extension ajoute une lettre AU DEBUT du suffixe (meme raison que
 * App\Search\SuffixExtensionLinksBuilder) : le joker `_` du LIKE se place donc en TETE du motif,
 * pas en queue.
 */
final class LengthSuffixExtensionLinksBuilder
{
    /**
     * Doublons de contenu (D-044, verification EXTERNE faite AVANT application du lot SEO --
     * scripts/seo-batches/length-prefix-suffix-2-2026-08-31.php, meme methodologie que D-039/
     * D-040/D-041). 386 cles "{longueur}:{2 lettres}" dont la page cible est en realite un
     * doublon de contenu exact d'une page deja indexee (noindex,follow + canonical vers la forme
     * gagnante dans le registre) -- jamais rendues ici, meme precedent que
     * App\Search\SuffixExtensionLinksBuilder::EXTERNAL_DUPLICATE_SUFFIXES (D-041). Voir
     * App\Search\LengthPrefixExtensionLinksBuilder::EXTERNAL_DUPLICATE_KEYS pour le pendant
     * prefixe (liste distincte, aucune cle commune par construction : familles differentes).
     *
     * Liste figee : valable pour l'etat actuel de storage/dictionary_fr.sqlite. Une
     * reconstruction future de la base devra revalider cette liste.
     *
     * COMPLETEE PAR D-047 (2026-08-31, balayage generique post-D-045/D-046) : +129 cles
     * supplementaires (extraites directement du lot storage/seo_fr.sqlite/word_list_terminant
     * deja applique au registre) -- 515 au total. Decouverte tardive : ce lot avait ete applique
     * au registre reel des sa decouverte mais laissait ce builder generer des liens internes
     * VIVANTS vers ces pages devenues noindex,follow (violation R5, confirmee en direct via HTTP
     * avant correctif : /mots/2-lettres/terminant/a liait vers /mots/2-lettres/terminant/aa,
     * noindex depuis D-047). Voir docs/DECISIONS.md D-047/D-048.
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
        '10:AP', '10:BU', '10:BY', '10:CO', '10:EG', '10:HR', '10:HY', '10:IM',
        '10:MB', '10:OD', '10:RN', '10:SO', '10:UI', '10:UQ', '10:VS', '10:YL',
        '10:YO', '10:ZS', '11:CO', '11:CQ', '11:EZ', '11:HT', '11:LK', '11:OF',
        '11:OM', '11:OV', '11:OW', '11:PL', '11:SY', '12:AP', '12:EU', '12:IF',
        '12:KI', '12:LU', '12:LY', '12:MB', '12:MT', '12:NC', '12:NZ', '12:UM',
        '12:UN', '12:VS', '13:CH', '13:CK', '13:DO', '13:IF', '13:IZ', '13:NG',
        '13:NN', '14:CH', '14:EZ', '14:IF', '14:NG', '14:OR', '14:UM', '14:UX',
        '15:AI', '15:CH', '15:IF', '15:MB', '15:RD', '15:ST', '15:UM', '15:UX',
        '2:AA', '2:AB', '2:AC', '2:AG', '2:AH', '2:AI', '2:AK', '2:AM',
        '2:AN', '2:AR', '2:AS', '2:AU', '2:AV', '2:AY', '2:BA', '2:BD',
        '2:BE', '2:BG', '2:BI', '2:BM', '2:BU', '2:BY', '2:CA', '2:CC',
        '2:CD', '2:CE', '2:CH', '2:CI', '2:CU', '2:CV', '2:DA', '2:DE',
        '2:DJ', '2:DO', '2:DU', '2:ED', '2:EH', '2:EJ', '2:EK', '2:EN',
        '2:ES', '2:ET', '2:EU', '2:EV', '2:EX', '2:FA', '2:FI', '2:FM',
        '2:GA', '2:GD', '2:GE', '2:GO', '2:GP', '2:HA', '2:HE', '2:HI',
        '2:HO', '2:HT', '2:IA', '2:IF', '2:IL', '2:IN', '2:IP', '2:IS',
        '2:IV', '2:JA', '2:JE', '2:JI', '2:KA', '2:KO', '2:KU', '2:LA',
        '2:LE', '2:LI', '2:LT', '2:LU', '2:MA', '2:MC', '2:ME', '2:MI',
        '2:MR', '2:MU', '2:NA', '2:NB', '2:NE', '2:NI', '2:NO', '2:NU',
        '2:OC', '2:OH', '2:OI', '2:OK', '2:OM', '2:ON', '2:OR', '2:OS',
        '2:OU', '2:PA', '2:PB', '2:PC', '2:PD', '2:PE', '2:PH', '2:PI',
        '2:PK', '2:PM', '2:PS', '2:PU', '2:PV', '2:QI', '2:QQ', '2:QU',
        '2:RA', '2:RB', '2:RE', '2:RI', '2:RU', '2:RY', '2:SA', '2:SE',
        '2:SF', '2:SH', '2:SI', '2:SM', '2:SR', '2:SU', '2:TA', '2:TE',
        '2:TI', '2:TO', '2:TU', '2:TV', '2:UA', '2:UD', '2:UN', '2:US',
        '2:UT', '2:VA', '2:VE', '2:VS', '2:VU', '2:WU', '2:XE', '2:XI',
        '2:YE', '2:YI', '2:YO', '2:ZA', '3:AJ', '3:AQ', '3:BC', '3:BE',
        '3:BN', '3:CB', '3:CC', '3:CK', '3:CM', '3:CX', '3:DC', '3:DD',
        '3:DF', '3:DH', '3:DL', '3:DM', '3:DN', '3:DR', '3:DV', '3:EJ',
        '3:FA', '3:FS', '3:GF', '3:GH', '3:GM', '3:GN', '3:GP', '3:GV',
        '3:HB', '3:IH', '3:II', '3:JD', '3:JO', '3:JS', '3:LT', '3:MH',
        '3:MW', '3:NP', '3:OH', '3:OW', '3:PC', '3:PN', '3:QC', '3:QN',
        '3:QP', '3:RH', '3:RL', '3:RM', '3:RP', '3:RR', '3:SB', '3:SD',
        '3:SF', '3:SG', '3:SH', '3:SM', '3:SO', '3:SS', '3:SW', '3:TF',
        '3:TM', '3:TN', '3:TP', '3:TV', '3:UX', '3:UZ', '3:VA', '3:VC',
        '3:VD', '3:VG', '3:WA', '3:WE', '3:XL', '3:YM', '3:ZI', '3:ZO',
        '3:ZP', '3:ZT', '3:ZY', '4:BD', '4:BG', '4:BM', '4:BN', '4:BT',
        '4:CB', '4:CY', '4:DG', '4:FD', '4:FR', '4:FU', '4:GH', '4:GT',
        '4:HM', '4:HN', '4:HR', '4:HS', '4:HT', '4:IH', '4:IV', '4:IZ',
        '4:JD', '4:JI', '4:JJ', '4:JS', '4:JU', '4:KR', '4:LB', '4:LC',
        '4:LD', '4:LG', '4:LR', '4:LX', '4:MF', '4:NF', '4:NJ', '4:NQ',
        '4:NY', '4:OX', '4:PC', '4:PP', '4:PY', '4:QC', '4:QE', '4:QF',
        '4:QH', '4:RG', '4:SL', '4:SM', '4:TC', '4:UJ', '4:UO', '4:UW',
        '4:UZ', '4:VP', '4:VS', '4:VY', '4:WL', '4:WN', '4:XS', '4:XY',
        '4:YN', '4:YR', '4:ZF', '4:ZS', '4:ZU', '5:AA', '5:AJ', '5:AQ',
        '5:AV', '5:AW', '5:AZ', '5:BZ', '5:CP', '5:DJ', '5:DT', '5:EH',
        '5:EQ', '5:FU', '5:HL', '5:HN', '5:HY', '5:IV', '5:IZ', '5:KR',
        '5:LB', '5:LF', '5:LH', '5:LZ', '5:NK', '5:NN', '5:NX', '5:OA',
        '5:OG', '5:PH', '5:PP', '5:PY', '5:RL', '5:RP', '5:SC', '5:SY',
        '5:TF', '5:UH', '5:UV', '5:UY', '5:WL', '5:XO', '5:YD', '5:YK',
        '5:YU', '5:ZU', '6:AA', '6:AF', '6:AQ', '6:AV', '6:AW', '6:CL',
        '6:CY', '6:DH', '6:DJ', '6:EB', '6:EP', '6:EQ', '6:EV', '6:FR',
        '6:IG', '6:IP', '6:IQ', '6:IU', '6:KY', '6:LD', '6:LM', '6:LR',
        '6:MM', '6:MP', '6:NZ', '6:OB', '6:OH', '6:OV', '6:OW', '6:PU',
        '6:QA', '6:RG', '6:RZ', '6:SU', '6:UO', '6:UY', '6:UZ', '6:XI',
        '6:YK', '6:YU', '6:ZI', '7:AF', '7:AQ', '7:BY', '7:CQ', '7:DY',
        '7:EK', '7:IG', '7:IU', '7:JA', '7:JO', '7:JS', '7:MM', '7:NJ',
        '7:NY', '7:OA', '7:OB', '7:OQ', '7:OW', '7:PO', '7:RL', '7:SK',
        '7:TZ', '7:UO', '7:VS', '7:WE', '7:YR', '7:YU', '7:ZY', '8:AZ',
        '8:DJ', '8:EB', '8:EO', '8:EQ', '8:EX', '8:FT', '8:HM', '8:HN',
        '8:HR', '8:KT', '8:LK', '8:MM', '8:MP', '8:NH', '8:OB', '8:OD',
        '8:OF', '8:OO', '8:OZ', '8:PF', '8:RM', '8:RZ', '8:TY', '8:WA',
        '8:WN', '9:AE', '9:AF', '9:AG', '9:EG', '9:EK', '9:EW', '9:FF',
        '9:HN', '9:IG', '9:IV', '9:JU', '9:OD', '9:RM', '9:SY', '9:UB',
        '9:UK', '9:UY', '9:YX',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(int $length, string $suffix): LengthSuffixExtensionLinks
    {
        if (strlen($suffix) !== 1) {
            return new LengthSuffixExtensionLinks(links: [], queryCount: 0);
        }

        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'length_suffix2' AND list_key LIKE ?"
        );
        $statement->execute([$length . ':_' . $suffix]);

        $links = [];

        foreach ($statement as $row) {
            $listKey = (string) $row['list_key'];

            if (in_array($listKey, self::EXTERNAL_DUPLICATE_KEYS, true)) {
                continue;
            }

            [, $extendedSuffix] = explode(':', $listKey, 2);

            $url = WordListFilters::fromPath(
                $length . '-lettres/terminant/' . strtolower($extendedSuffix)
            )?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['suffix' => $extendedSuffix, 'url' => $url, 'count' => (int) $row['count']];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['suffix'] <=> $b['suffix']);

        return new LengthSuffixExtensionLinks(links: $links, queryCount: 1);
    }
}
