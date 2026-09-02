<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\StartEndWithLinks depuis list_counts (list_type 'start_end_with'), meme
 * principe que App\Search\PositionLinksBuilder / App\Search\AvecSansLengthLinksBuilder -- une
 * seule requete triviale, aucun calcul sur `terms` au runtime (voir
 * scripts/build_explore_hub_counts.php pour la mesure qui impose ce detour, et
 * scripts/bench_start_end_with_build.php pour la comparaison chiffree avec l'alternative SQL).
 *
 * list_key est toujours "{debut}:{fin}:{lettre}" pour 'start_end_with' -- une seule direction de
 * lecture necessaire (contrairement a App\Search\LetterCombinedLinksBuilder/
 * App\Search\LengthCombinedLinksBuilder, qui doivent lire "start_end"/"length_start_end" dans les
 * DEUX sens depuis deux pages source distinctes) : la page source de ce maillage est toujours
 * /mots/commencant/{X}/terminant/{Y}, debut ET fin sont donc TOUJOURS connus simultanement --
 * `list_key LIKE '{debut}:{fin}:%'` reste un prefixe exact, servi par l'index de cle primaire.
 *
 * Budget runtime : 1 requete SQLite par page.
 *
 * Trois filtres anti-doublon successifs, appliques dans build() ci-dessous (voir chaque
 * constante pour le detail) : DUPLICATE_CONTENT_KEYS (D-037, doublon avec la page PARENTE
 * commencant+terminant), SIBLING_DUPLICATE_KEYS (D-038, doublon entre pages SOEURS "avec" du
 * meme panier), CROSS_DUPLICATE_LENGTH_KEYS (3e audit consolide, 2026-08-19, doublon CROISE avec
 * la page LONGUEUR de la MEME paire, App\Search\LengthLinksBuilder::byStartEnd -- une famille
 * SEO differente, Family::WORD_LIST_COMBINED contre Family::WORD_LIST_COMBINED_WITH_LETTER).
 */
final class StartEndWithLinksBuilder
{
    // CORRECTIF PERF (2026-09-01, meme pattern que AvecFourLettersLinksBuilder) : in_array($key,
    // self::X_KEYS, true) sur ces quatre constantes est un parcours lineaire relance a CHAQUE
    // ligne list_counts examinee dans build(). Tables de hachage calculees UNE FOIS par process
    // (cache statique), lookups O(1) au lieu de O(n) -- aucun changement de contenu.
    private static ?array $duplicateContentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $crossDuplicateLengthKeySet = null;
    private static ?array $externalDuplicateKeySet = null;

    /**
     * Les 207 triples (debut, fin, lettre) a contenu strictement DUPLIQUE avec leur page parente
     * /mots/commencant/{debut}/terminant/{fin} (sans "avec") -- distinct du collapse D-032
     * (lettre "avec" == debut ou fin, deja gere par la comparaison $url !== $parentUrl ci-dessous,
     * jamais reintroduit ici) : ici l'URL enfant EST DIFFERENTE de l'URL parente (lettre "avec"
     * ni debut ni fin), mais TOUS les mots de la paire commencant+terminant contiennent deja
     * cette lettre -- ajouter la contrainte "avec" ne retire aucun mot, le contenu reste
     * identique. Exemple historique trouve par l'audit consolide (2026-08-18, NO GO) : la paire
     * X:O (longueur 5) ne contient que XIPHO -- /mots/commencant/x/terminant/o/avec/h, .../avec/i
     * et .../avec/p sont TOUTES identiques entre elles ET a leur parent
     * /mots/commencant/x/terminant/o (exemple TOUJOURS VALIDE apres D-051/D-052). L'AUTRE exemple
     * historique, la paire F:Q (qui ne contenait que FAQ) N'EST PLUS un doublon de contenu depuis
     * D-051/D-052 (voir la note de revalidation ci-dessous) -- F:Q:A retiree de cette liste.
     *
     * Regle de detection (meme patron que App\Search\LengthLinksBuilder::
     * DUPLICATE_START_END_KEYS, et que le cas 'combined_with_length' de scripts/
     * propose_seo_batch.php, ligne ~704) : une ligne list_counts 'start_end_with'
     * "{debut}:{fin}:{lettre}" est un doublon de contenu SI ET SEULEMENT SI son `count` est
     * EXACTEMENT EGAL au `count` de l'entree parente correspondante 'start_end' "{debut}:{fin}"
     * (sans la lettre "avec") -- ca signifie que TOUS les mots de la paire contiennent deja cette
     * lettre.
     *
     * Verifie par DEUX methodes independantes (meme discipline que les 52 paires D-025/I-1) :
     * 1. comparaison list_counts ('start_end_with' vs 'start_end', count enfant === count parent),
     *    sur les 11 964 lignes 'start_end_with' non degenerees D-032 (1 239 exclues avant
     *    comparaison, meme raison que ci-dessus) -- 207 trouvees
     * 2. balayage complet et INDEPENDANT (sans partir de list_counts), requete directe sur `terms`
     *    pour les 26 lettres x 632 paires commencant+terminant reelles, compare a
     *    COUNT(*) WHERE debut ET fin seuls -- 207 trouvees, 0 divergence dans les deux sens avec
     *    la methode 1
     *
     * REVALIDATION D-051/D-052 (2026-09-02, 838 180 -> 844 961 termes, 227 -> 207) : le complement
     * kaikki a ajoute des sigles/toponymes courts qui cassent plusieurs relations -- ex. F:Q
     * (commencant F, terminant Q) valait 1 seul mot (FAQ) a 838 180 termes, vaut desormais 4
     * (FAQ, FITEQ, FLQ, FTQ -- tous des sigles) : F:Q:A (compte 1, seul FAQ a A) ne correspond
     * plus au panier complet (4) -- CASSE, "F:Q:A" SORTIE de la liste. Confirme par la monotonie
     * de l'ajout de mots (D-051, AJOUTE uniquement) : une relation PARENT ne peut que se BRISER en
     * ajoutant des mots -- mais RECALCUL COMPLET effectue (pas un simple diff de l'ancienne liste,
     * voir App\Search\LengthLinksBuilder::DUPLICATE_START_END_KEYS pour un exemple ou de nouvelles
     * paires commencant+terminant inexistantes auparavant peuvent aussi apparaitre).
     *
     * Liste figee : valable pour l'etat actuel de storage/dictionary_fr.sqlite (844 961 termes,
     * D-051/D-052, integrity_check = ok). Une reconstruction future de la base devra revalider
     * cette liste (meme avertissement que DUPLICATE_START_END_KEYS).
     *
     * @var list<string>
     */
    private const DUPLICATE_CONTENT_KEYS = [
        'A:J:D', 'B:J:D', 'B:J:O', 'B:J:R', 'C:J:I', 'C:W:O', 'D:Q:C', 'D:W:A',
        'F:W:L', 'F:W:O', 'G:Q:O', 'G:W:O', 'H:J:A', 'H:Q:B', 'H:Q:U', 'H:Z:E',
        'I:B:C', 'I:B:E', 'I:B:L', 'I:B:N', 'I:B:R', 'I:B:T', 'I:B:U', 'I:W:E',
        'I:W:N', 'I:W:R', 'I:W:T', 'I:W:V', 'J:X:U', 'K:C:A', 'K:J:A', 'K:J:M',
        'K:J:N', 'K:J:R', 'K:J:U', 'K:Q:A', 'K:Q:J', 'K:Q:U', 'L:Q:C', 'L:Q:E',
        'L:W:O', 'M:J:A', 'M:Q:C', 'M:Q:N', 'M:Q:O', 'M:V:A', 'M:V:H', 'M:V:O',
        'M:W:A', 'M:W:H', 'M:W:L', 'M:W:O', 'M:W:R', 'M:W:S', 'N:H:A', 'N:H:L',
        'N:V:E', 'N:W:A', 'N:W:D', 'N:W:T', 'N:Z:E', 'O:J:I', 'O:J:N', 'O:Q:C',
        'O:Q:E', 'O:Q:I', 'O:Q:R', 'O:Q:S', 'O:Q:U', 'Q:D:U', 'Q:K:U', 'Q:L:U',
        'Q:O:U', 'Q:P:C', 'Q:P:E', 'Q:P:I', 'Q:P:K', 'Q:P:S', 'Q:P:T', 'Q:P:U',
        'Q:X:U', 'R:Q:C', 'R:Q:E', 'R:Q:M', 'R:Q:O', 'R:Q:S', 'R:Q:T', 'R:W:A',
        'R:W:K', 'T:J:A', 'T:J:K', 'T:J:O', 'T:Q:A', 'T:Q:C', 'T:Q:E', 'T:Q:H',
        'T:Q:M', 'T:W:A', 'T:W:H', 'T:W:K', 'T:W:L', 'T:W:O', 'T:W:S', 'U:B:S',
        'U:G:I', 'U:G:N', 'U:V:I', 'U:V:N', 'U:Y:R', 'U:Z:E', 'V:B:C', 'V:B:D',
        'V:B:E', 'V:B:I', 'V:B:L', 'V:B:O', 'V:B:U', 'V:J:E', 'V:J:N', 'V:J:O',
        'V:J:R', 'V:Q:C', 'V:Q:D', 'V:Q:I', 'V:Q:O', 'V:V:A', 'W:C:O', 'W:L:A',
        'W:L:E', 'W:O:R', 'W:Q:C', 'W:Q:E', 'W:Q:I', 'W:Q:R', 'W:Q:V', 'X:C:A',
        'X:C:D', 'X:C:E', 'X:C:G', 'X:C:I', 'X:C:N', 'X:C:O', 'X:C:S', 'X:C:T',
        'X:G:A', 'X:G:I', 'X:G:N', 'X:O:H', 'X:O:I', 'X:O:P', 'X:R:E', 'X:T:A',
        'X:T:G', 'X:T:H', 'X:T:I', 'X:T:O', 'X:T:P', 'X:T:R', 'X:U:G', 'X:U:I',
        'X:U:N', 'X:U:O', 'X:X:A', 'X:X:C', 'X:X:E', 'X:X:I', 'X:X:N', 'X:X:O',
        'X:X:R', 'X:X:S', 'X:X:T', 'X:X:U', 'X:Z:A', 'X:Z:E', 'X:Z:G', 'X:Z:H',
        'X:Z:I', 'X:Z:O', 'X:Z:P', 'X:Z:R', 'Y:C:A', 'Y:C:F', 'Y:C:I', 'Y:C:N',
        'Y:F:O', 'Y:F:S', 'Y:F:U', 'Y:G:A', 'Y:G:N', 'Y:P:O', 'Y:P:U', 'Y:Q:E',
        'Y:Q:L', 'Y:V:O', 'Y:V:U', 'Y:Y:A', 'Y:Y:O', 'Z:J:B', 'Z:J:D', 'Z:J:E',
        'Z:J:O', 'Z:J:U', 'Z:Q:D', 'Z:Q:E', 'Z:Q:I', 'Z:Q:N', 'Z:X:U',
    ];

    /**
     * Doublons de contenu entre pages SOEURS "avec" (I-A, 2e audit consolide de la serie,
     * 2026-08-18, GO avec ce point non bloquant) : distinct de DUPLICATE_CONTENT_KEYS ci-dessus
     * (comparaison a la page PARENTE, sans "avec") -- ici, DEUX lettres "avec" DIFFERENTES du
     * MEME panier commencant+terminant (ni l'une ni l'autre egale au panier complet, donc aucune
     * des deux n'est detectee par DUPLICATE_CONTENT_KEYS) isolent neanmoins EXACTEMENT le meme
     * SOUS-ENSEMBLE de mots -- ex. la paire A:B (7 mots apres D-051/D-052 : AB, ACHAB, ACHEB,
     * AEROCLUB, ANTIPUB, APLOMB, AUTOLUB) : "avec/l" et "avec/o" listent toutes deux EXACTEMENT
     * {AEROCLUB, APLOMB, AUTOLUB} -- L (plus petite alphabetiquement) reste candidate, O est
     * exclue ici. (Exemple historique a 838 180 termes : "avec/c" et "avec/e" isolaient {ACHEB,
     * AEROCLUB} -- CASSE par D-051/D-052, le complement kaikki a ajoute ACHAB, qui contient C mais
     * pas E, voir la note de revalidation ci-dessous. Un autre couple, I/N, isole desormais lui
     * aussi EXACTEMENT {ANTIPUB} mais A:B:I est par ailleurs deja exclu par
     * EXTERNAL_DUPLICATE_KEYS pour une raison sans rapport, D-041 -- L/O reste l'exemple le plus
     * clair, non confondu avec un autre filtre.)
     *
     * Regle de detection : pour une paire {debut}:{fin} donnee, deux lettres "avec" Z1 et Z2
     * (toutes deux SURVIVANTES du filtre D-032 + DUPLICATE_CONTENT_KEYS ci-dessus, c-a-d
     * actuellement produites par build()) sont un doublon de contenu SOEUR SSI, pour TOUS les
     * mots du panier {debut}:{fin}, la presence de Z1 et la presence de Z2 coincident toujours
     * (Z1 et Z2 apparaissent et disparaissent toujours ENSEMBLE d'un mot a l'autre) -- ca
     * signifie que le sous-ensemble de mots contenant Z1 est EXACTEMENT le meme ensemble que
     * celui contenant Z2, pas seulement un ensemble de meme taille. Regroupe les lettres liees
     * par cette relation (classes d'equivalence) ; canonicalisation deterministe : la lettre
     * alphabetiquement la plus petite de chaque classe reste candidate a l'indexation/au
     * maillage, les autres membres de la classe sont exclus ici.
     *
     * Verifie par DEUX methodes independantes sur le MEME panier de mots recupere une seule fois
     * par paire (611 paires reelles, 9 923 lettres "avec" survivantes au total) :
     * 1. regroupement DIRECT par egalite d'ensemble exacte : cle = liste triee des mots
     *    concernes, jointe par un separateur non ambigu -- comparaison de chaines completes,
     *    aucune notion de hash approximatif, aucune collision possible
     * 2. regroupement par PROPRIETE DE COINCIDENCE, algorithme different (suggere par l'audit) :
     *    pour chaque paire de lettres candidates (Z1, Z2) du meme panier, teste si presence(Z1)
     *    == presence(Z2) pour TOUS les mots du panier (union-find sur cette relation binaire)
     * 0 divergence entre les deux methodes sur l'integralite des 632 paires reelles (pas un
     * echantillon) -- 292 groupes trouves (172 paires affectees), 446 lettres a exclure au
     * total (unicite verifiee : aucune lettre n'appartient a deux groupes differents) -- REVALIDE
     * D-051/D-052 (2026-09-02, etait 283 groupes/169 paires/428 lettres sur 838 180 termes,
     * RECALCUL COMPLET depuis `terms`, pas un patch de l'ancienne liste). Aucun cas trouve sur
     * l'axe 4 (voir PrefixAvecLinksBuilder::SIBLING_DUPLICATE_KEYS, liste vide) -- les paniers
     * mono-lettre restent trop grands pour qu'une paire de lettres y soit toujours liee.
     *
     * Rapport complet a 838 180 termes (liste des 283 groupes d'origine, paires/prefixes
     * concernes, mots impliques) : reports/query-plans/avec-doublons-soeurs-correctif.md -- PAS
     * regenere pour D-051/D-052, la liste figee ci-dessous est la source de verite courante.
     *
     * Liste figee : valable pour l'etat actuel de storage/dictionary_fr.sqlite (844 961 termes,
     * D-051/D-052). Une reconstruction future devra revalider cette liste (meme avertissement que
     * DUPLICATE_CONTENT_KEYS ci-dessus).
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [
        'A:B:N', 'A:B:O', 'A:K:V', 'A:K:X', 'A:Q:K', 'A:Q:L', 'A:Q:U', 'A:V:E',
        'A:V:M', 'A:V:T', 'B:B:H', 'B:F:V', 'B:Q:N', 'B:Q:P', 'B:Q:T', 'B:V:I',
        'B:V:L', 'B:V:N', 'B:V:R', 'B:V:S', 'B:W:G', 'B:W:L', 'B:W:N', 'B:W:O',
        'B:W:U', 'C:H:W', 'C:V:N', 'C:V:U', 'C:W:L', 'D:B:I', 'D:B:O', 'D:B:W',
        'D:P:T', 'D:Q:H', 'D:Q:N', 'D:Q:O', 'D:Q:R', 'D:V:H', 'D:V:K', 'D:V:R',
        'D:V:T', 'E:G:M', 'E:G:O', 'E:G:U', 'E:K:F', 'E:K:L', 'E:K:N', 'E:K:O',
        'E:P:C', 'E:P:S', 'E:V:R', 'E:V:U', 'F:B:M', 'F:B:N', 'F:B:S', 'F:B:U',
        'F:H:P', 'F:J:U', 'F:P:H', 'F:P:M', 'F:P:R', 'F:P:S', 'F:Q:I', 'G:B:D',
        'G:B:F', 'G:B:L', 'G:B:O', 'G:B:R', 'G:B:U', 'G:C:S', 'G:G:T', 'G:H:N',
        'G:H:R', 'G:K:S', 'G:K:T', 'G:P:N', 'H:B:J', 'H:C:V', 'H:D:V', 'H:F:T',
        'H:F:Y', 'H:H:Z', 'H:K:L', 'H:Q:E', 'H:Q:L', 'H:Q:O', 'I:C:M', 'I:C:N',
        'I:C:S', 'I:D:J', 'I:G:U', 'I:H:M', 'I:H:O', 'I:H:T', 'I:H:U', 'I:H:V',
        'I:K:T', 'I:P:O', 'I:P:R', 'I:Q:M', 'I:Q:P', 'I:V:T', 'I:Y:G', 'I:Y:N',
        'I:Y:O', 'I:Y:U', 'J:C:F', 'J:F:C', 'J:G:H', 'J:G:N', 'J:G:S', 'J:H:K',
        'J:H:L', 'J:H:N', 'J:H:S', 'J:H:T', 'J:H:V', 'J:K:L', 'J:K:R', 'J:K:S',
        'J:K:T', 'J:K:U', 'J:K:Y', 'J:M:H', 'J:O:R', 'J:P:K', 'J:P:O', 'K:C:S',
        'K:D:L', 'K:F:L', 'K:F:T', 'K:G:P', 'K:G:X', 'K:M:N', 'K:M:V', 'K:M:Z',
        'K:P:B', 'K:P:H', 'K:P:T', 'K:V:U', 'K:W:B', 'K:W:I', 'K:W:N', 'K:X:V',
        'K:Y:L', 'L:B:I', 'L:B:M', 'L:B:P', 'L:B:U', 'L:D:H', 'L:K:M', 'L:P:E',
        'L:P:G', 'L:P:N', 'L:P:R', 'L:P:S', 'L:V:O', 'L:V:T', 'M:P:G', 'M:P:S',
        'M:Q:U', 'M:V:K', 'M:V:L', 'N:B:H', 'N:B:L', 'N:B:T', 'N:G:H', 'N:G:L',
        'N:G:T', 'N:H:G', 'N:H:I', 'N:H:R', 'N:H:U', 'N:P:I', 'N:P:M', 'N:P:O',
        'N:P:S', 'N:Q:H', 'N:Q:M', 'N:Q:S', 'N:Q:T', 'N:Q:U', 'N:Q:V', 'N:V:O',
        'N:V:S', 'N:V:T', 'N:V:U', 'O:D:W', 'O:F:Y', 'O:G:V', 'O:H:E', 'O:H:K',
        'O:H:P', 'O:H:R', 'O:H:S', 'O:H:Y', 'O:K:J', 'O:K:X', 'O:K:Z', 'O:P:R',
        'O:P:T', 'O:P:U', 'O:W:L', 'O:W:S', 'O:W:T', 'O:W:U', 'O:Y:J', 'O:Y:T',
        'O:Y:W', 'P:B:D', 'P:B:J', 'P:B:M', 'P:B:N', 'P:H:V', 'P:W:H', 'P:W:I',
        'P:W:L', 'P:W:O', 'P:W:S', 'Q:C:G', 'Q:C:I', 'Q:C:L', 'Q:C:N', 'Q:C:U',
        'Q:C:V', 'Q:G:H', 'Q:G:N', 'Q:G:O', 'Q:G:S', 'Q:H:D', 'Q:H:I', 'Q:H:L',
        'Q:H:T', 'Q:K:E', 'Q:K:R', 'Q:K:T', 'Q:M:H', 'Q:M:I', 'Q:O:H', 'Q:Q:U',
        'Q:U:M', 'Q:U:R', 'Q:Y:N', 'Q:Y:W', 'R:F:K', 'R:K:V', 'R:K:Y', 'R:K:Z',
        'R:P:O', 'R:P:V', 'R:V:O', 'R:V:S', 'R:V:U', 'R:W:H', 'R:W:I', 'R:W:S',
        'S:F:Z', 'S:Q:I', 'S:Q:L', 'S:Q:O', 'S:Q:U', 'S:V:C', 'S:V:O', 'S:W:U',
        'T:B:M', 'T:B:P', 'T:B:R', 'T:B:Y', 'T:H:J', 'T:K:J', 'T:P:L', 'T:P:W',
        'T:V:E', 'T:V:H', 'T:V:R', 'U:C:L', 'U:C:S', 'U:D:G', 'U:D:I', 'U:D:L',
        'U:D:P', 'U:D:R', 'U:D:S', 'U:D:T', 'U:F:E', 'U:F:L', 'U:G:O', 'U:G:P',
        'U:G:W', 'U:G:X', 'U:G:Y', 'U:H:T', 'U:K:E', 'U:K:L', 'U:K:R', 'U:K:S',
        'U:K:Z', 'U:M:X', 'U:O:N', 'U:P:E', 'U:P:F', 'U:P:I', 'U:P:L', 'U:P:N',
        'U:P:R', 'U:Y:G', 'V:C:Y', 'V:F:N', 'V:F:S', 'V:H:C', 'V:H:L', 'V:H:S',
        'V:K:N', 'V:K:R', 'V:K:S', 'V:P:D', 'V:P:M', 'V:P:U', 'V:V:K', 'V:V:L',
        'V:V:S', 'V:V:Y', 'W:B:N', 'W:B:R', 'W:B:T', 'W:B:U', 'W:C:D', 'W:C:E',
        'W:C:K', 'W:C:L', 'W:C:R', 'W:D:H', 'W:D:P', 'W:D:S', 'W:D:T', 'W:F:N',
        'W:F:P', 'W:F:S', 'W:G:U', 'W:K:C', 'W:K:E', 'W:K:M', 'W:K:R', 'W:K:T',
        'W:K:U', 'W:L:F', 'W:L:J', 'W:L:O', 'W:L:R', 'W:L:T', 'W:M:E', 'W:M:N',
        'W:O:D', 'W:O:E', 'W:O:I', 'W:O:T', 'W:P:O', 'W:T:K', 'W:U:N', 'W:U:P',
        'W:U:S', 'W:U:T', 'W:U:Z', 'W:X:E', 'W:X:H', 'W:X:R', 'W:Y:K', 'W:Y:S',
        'W:Z:K', 'W:Z:O', 'X:A:P', 'X:I:G', 'X:I:H', 'X:I:O', 'X:I:P', 'X:I:R',
        'X:I:Y', 'X:L:N', 'X:L:R', 'X:L:S', 'X:M:D', 'X:M:E', 'X:M:H', 'X:M:I',
        'X:M:L', 'X:M:N', 'X:M:O', 'X:M:U', 'X:R:H', 'X:R:I', 'X:R:P', 'X:R:Y',
        'X:T:Y', 'X:Z:Y', 'Y:D:L', 'Y:G:H', 'Y:G:J', 'Y:G:L', 'Y:G:R', 'Y:G:T',
        'Y:H:R', 'Y:H:U', 'Y:H:V', 'Y:H:Z', 'Y:K:M', 'Y:L:E', 'Y:L:T', 'Y:M:E',
        'Y:M:R', 'Y:M:T', 'Y:M:U', 'Y:O:J', 'Y:R:H', 'Y:R:K', 'Y:T:Q', 'Y:U:G',
        'Y:U:M', 'Y:U:N', 'Y:U:P', 'Y:U:S', 'Y:X:N', 'Y:X:T', 'Y:Y:M', 'Y:Y:N',
        'Y:Y:R', 'Y:Z:N', 'Z:B:R', 'Z:C:R', 'Z:C:S', 'Z:C:T', 'Z:C:Y', 'Z:D:I',
        'Z:D:M', 'Z:D:R', 'Z:D:T', 'Z:F:D', 'Z:F:N', 'Z:F:S', 'Z:H:R', 'Z:H:U',
        'Z:H:V', 'Z:H:Y', 'Z:K:N', 'Z:K:W', 'Z:L:P', 'Z:M:W',
    ];

    /**
     * Doublons de contenu CROISES entre DEUX FAMILLES DIFFERENTES qui partagent le meme panier de
     * base commencant+terminant {debut}:{fin} (3e audit consolide de la serie, 2026-08-19, NO GO) :
     * - axe 1 (App\Search\LengthLinksBuilder::byStartEnd, "/mots/{N}-lettres/commencant/{X}/
     *   terminant/{Y}", D-027/D-035, apres exclusion des 52 doublons D-025/I-1) -- tranche le
     *   panier {debut}:{fin} PAR LONGUEUR
     * - axe 2 (ce builder, "/mots/commencant/{X}/terminant/{Y}/avec/{Z}", D-033/D-035/D-037/D-038,
     *   apres exclusion des 1239 lignes degenerees D-032, des 207 doublons-parent DUPLICATE_
     *   CONTENT_KEYS et des 446 doublons-soeurs SIBLING_DUPLICATE_KEYS, D-051/D-052) -- tranche le
     *   MEME panier PAR LETTRE "avec"
     * DUPLICATE_CONTENT_KEYS et SIBLING_DUPLICATE_KEYS ci-dessus comparent chacun une page "avec"
     * a une autre page "avec" (parente ou soeur) DE LA MEME FAMILLE -- aucun des deux ne compare
     * jamais a l'AUTRE famille (byStartEnd). Preuve sur pieces (audit) : la paire X:M (2 mots au
     * total, XALAM et XENODOCHIUM) -- "/mots/5-lettres/commencant/x/terminant/m" (axe 1) et
     * "/mots/commencant/x/terminant/m/avec/a" (axe 2, A = lettre canonique survivante du groupe
     * soeur {A,L} apres D-038) contiennent tous deux EXACTEMENT le meme mot unique, XALAM -- deux
     * URL distinctes, deux familles distinctes (Family::WORD_LIST_COMBINED contre Family::
     * WORD_LIST_COMBINED_WITH_LETTER), un seul et meme contenu.
     *
     * Regle de detection : pour une paire {debut}:{fin} donnee, une tranche LONGUEUR L (survivante
     * axe 1) et une tranche LETTRE "avec" Z (survivante axe 2) sont un doublon croise SSI
     * l'ensemble EXACT des mots de longueur L est EGAL a l'ensemble EXACT des mots contenant Z --
     * pas seulement un meme compte (les deux tranches ne sont PAS l'une un sous-ensemble naturel de
     * l'autre, contrairement aux comparaisons parent/enfant ou soeur/soeur ci-dessus, ou le
     * sous-ensemble est garanti par construction -- ici une simple egalite de COMPTE ne suffirait
     * pas a demontrer une egalite d'ENSEMBLE).
     *
     * Regle de priorite (deja tranchee cote produit, meme principe que D-025 -- la forme la plus
     * simple/generale gagne sur la plus specifique) : en cas de collision, la variante LONGUEUR
     * (axe 1, LengthLinksBuilder) reste candidate a l'indexation ; la variante "avec" (axe 2, CE
     * builder) est retiree ici. LengthLinksBuilder n'est PAS modifie par ce correctif.
     *
     * Verifie par DEUX methodes independantes sur les 632 paires reelles (REVALIDE D-051/D-052,
     * 2026-09-02, RECALCUL COMPLET depuis `terms`/list_counts, pas un patch de l'ancienne liste --
     * DOIT etre recalcule APRES App\Search\LengthLinksBuilder::DUPLICATE_START_END_KEYS, dont
     * l'axe 1 de cette comparaison depend directement : un premier essai fait dans le mauvais ordre
     * a produit 344, corrige a 354 en recalculant apres la mise a jour de l'axe 1) :
     * 1. appel des VRAIS builders (LengthLinksBuilder::build($length) pour les 14 longueurs 2-15,
     *    puis StartEndWithLinksBuilder::build($start,$end) pour les 632 paires), regroupement par
     *    paire, puis pour chaque couple (longueur survivante, lettre survivante) de la MEME paire :
     *    panier recupere une seule fois par paire (ORDER BY normalized), tranche par longueur
     *    (strlen()) et par lettre (str_contains()), comparaison de tableau complete (===) -- 354
     *    egalites trouvees (etait 333 sur 838 180 termes)
     * 2. pour chacun des 354 couples trouves par la methode 1, requete SQL DIRECTE et INDEPENDANTE
     *    (COUNT(length=L), COUNT(instr(normalized,Z)>0), COUNT(length=L AND instr(normalized,Z)>0)
     *    sur le panier {debut}:{fin}) : les trois comptes DOIVENT etre strictement egaux entre eux
     *    ET egaux au compte de mots trouve par la methode 1 -- une egalite triple prouve une
     *    egalite d'ensemble (sous-ensemble dans les deux sens) sans jamais comparer de tableaux --
     *    354/354 confirmes, 0 divergence
     * L'exemple X:M (XALAM/XENODOCHIUM) cite par l'audit d'origine confirme TOUJOURS present dans
     * la liste apres D-051/D-052. Rapport complet a 838 180 termes (methodologie detaillee, liste
     * des 333 couples d'origine, mots impliques) : reports/query-plans/
     * avec-doublons-croises-longueur-correctif.md -- PAS regenere pour D-051/D-052, la liste figee
     * ci-dessous est la source de verite courante.
     *
     * Disjoint par construction de DUPLICATE_CONTENT_KEYS et SIBLING_DUPLICATE_KEYS ci-dessus
     * (verifie explicitement, 0 intersection dans les deux sens) : cette liste ne compare jamais
     * une page "avec" a une autre page "avec", uniquement a une page LONGUEUR de l'autre famille.
     *
     * Liste figee : valable pour l'etat actuel de storage/dictionary_fr.sqlite (844 961 termes,
     * D-051/D-052). Une reconstruction future devra revalider cette liste (meme avertissement que
     * DUPLICATE_CONTENT_KEYS/SIBLING_DUPLICATE_KEYS ci-dessus).
     *
     * @var list<string>
     */
    private const CROSS_DUPLICATE_LENGTH_KEYS = [
        'A:B:H', 'A:B:M', 'A:B:R', 'A:B:T', 'A:F:Y', 'A:P:O', 'A:Q:D', 'A:Q:G',
        'A:V:B', 'A:V:D', 'A:V:N', 'A:V:S', 'A:Y:P', 'B:B:C', 'B:B:E', 'B:H:D',
        'B:K:Z', 'B:Q:Y', 'B:V:D', 'B:V:H', 'B:V:J', 'B:W:A', 'B:W:M', 'C:B:K',
        'C:B:N', 'C:B:P', 'C:H:D', 'C:J:D', 'C:P:S', 'C:Q:I', 'C:W:M', 'D:B:C',
        'D:B:K', 'D:B:M', 'D:C:G', 'D:J:E', 'D:K:T', 'D:P:B', 'D:P:K', 'D:Q:A',
        'D:Q:E', 'D:V:B', 'D:W:U', 'E:C:F', 'E:G:A', 'E:G:B', 'E:G:P', 'E:H:B',
        'E:H:D', 'E:H:M', 'E:H:N', 'E:K:C', 'E:K:I', 'E:K:Z', 'E:P:A', 'E:P:Q',
        'E:V:L', 'E:V:O', 'F:B:C', 'F:B:H', 'F:C:H', 'F:F:Q', 'F:J:D', 'F:J:E',
        'F:P:A', 'F:P:E', 'F:P:L', 'F:Q:E', 'F:Y:P', 'G:B:A', 'G:B:H', 'G:B:I',
        'G:C:V', 'G:G:K', 'G:H:D', 'G:H:V', 'G:K:H', 'G:K:L', 'G:K:P', 'G:K:V',
        'H:B:D', 'H:C:M', 'H:C:S', 'H:F:D', 'H:G:P', 'H:K:B', 'H:K:F', 'H:P:L',
        'H:Q:A', 'H:Q:C', 'H:Y:T', 'I:D:H', 'I:D:P', 'I:G:K', 'I:G:L', 'I:G:V',
        'I:H:B', 'I:H:D', 'I:H:E', 'I:H:K', 'I:P:N', 'I:Q:L', 'I:Q:N', 'I:Q:R',
        'I:Q:S', 'I:V:C', 'I:Y:A', 'I:Y:E', 'I:Y:V', 'J:B:C', 'J:C:D', 'J:C:M',
        'J:C:S', 'J:D:T', 'J:F:A', 'J:F:E', 'J:F:N', 'J:G:B', 'J:G:T', 'J:H:C',
        'J:K:B', 'J:K:O', 'J:L:Y', 'J:M:B', 'J:M:L', 'J:O:V', 'J:O:Y', 'J:P:H',
        'J:X:D', 'J:X:P', 'J:Y:T', 'K:B:E', 'K:C:E', 'K:D:A', 'K:D:O', 'K:F:M',
        'K:G:H', 'K:H:N', 'K:J:D', 'K:M:B', 'K:M:C', 'K:M:G', 'K:P:A', 'K:P:C',
        'K:P:L', 'K:P:R', 'K:V:M', 'K:V:N', 'K:Y:C', 'K:Y:I', 'L:B:A', 'L:B:D',
        'L:B:O', 'L:C:G', 'L:K:T', 'L:P:C', 'L:P:T', 'L:Q:O', 'L:Q:R', 'L:V:M',
        'M:B:D', 'M:B:J', 'M:B:Y', 'M:F:P', 'M:G:Z', 'M:P:D', 'M:Q:T', 'M:V:I',
        'M:V:S', 'N:B:G', 'N:B:M', 'N:C:H', 'N:F:D', 'N:F:P', 'N:F:V', 'N:G:C',
        'N:G:O', 'N:G:S', 'N:H:C', 'N:H:E', 'N:M:S', 'N:P:D', 'N:Q:I', 'N:V:G',
        'N:V:M', 'N:Y:C', 'N:Y:G', 'N:Y:H', 'O:C:F', 'O:C:P', 'O:F:M', 'O:G:A',
        'O:G:E', 'O:G:H', 'O:H:B', 'O:H:G', 'O:H:L', 'O:H:M', 'O:K:V', 'O:M:C',
        'O:P:G', 'O:P:N', 'O:W:A', 'O:W:M', 'P:B:A', 'P:B:L', 'P:H:B', 'P:H:J',
        'P:K:F', 'P:P:D', 'P:P:S', 'P:W:A', 'P:W:E', 'Q:C:A', 'Q:C:B', 'Q:D:S',
        'Q:F:C', 'Q:F:N', 'Q:G:A', 'Q:G:L', 'Q:H:A', 'Q:H:E', 'Q:K:B', 'Q:M:E',
        'Q:M:T', 'Q:M:V', 'Q:Q:T', 'Q:U:D', 'Q:Y:S', 'R:B:L', 'R:B:S', 'R:H:B',
        'R:O:V', 'R:P:C', 'R:P:M', 'R:V:K', 'R:W:C', 'R:W:O', 'R:X:Q', 'S:B:P',
        'S:H:B', 'S:H:F', 'S:Q:C', 'S:Q:M', 'S:V:A', 'S:V:E', 'S:V:K', 'S:W:C',
        'S:W:D', 'S:W:Q', 'T:B:H', 'T:F:P', 'T:H:V', 'T:K:Q', 'T:P:U', 'T:V:K',
        'T:Y:V', 'U:C:H', 'U:C:Q', 'U:D:E', 'U:D:F', 'U:D:H', 'U:F:C', 'U:G:B',
        'U:G:L', 'U:H:A', 'U:K:A', 'U:K:B', 'U:O:D', 'U:O:L', 'U:P:D', 'U:P:M',
        'U:Y:A', 'U:Y:T', 'V:C:M', 'V:C:S', 'V:G:D', 'V:G:H', 'V:G:K', 'V:G:T',
        'V:H:B', 'V:H:T', 'V:K:G', 'V:K:J', 'V:K:U', 'V:M:P', 'V:P:E', 'V:Q:E',
        'V:V:I', 'V:Y:H', 'W:B:A', 'W:B:S', 'W:C:B', 'W:C:H', 'W:C:N', 'W:D:M',
        'W:F:D', 'W:F:E', 'W:F:Q', 'W:G:C', 'W:H:K', 'W:K:B', 'W:K:L', 'W:L:G',
        'W:L:U', 'W:O:P', 'W:P:K', 'W:X:C', 'W:X:M', 'W:Y:N', 'X:G:J', 'X:L:P',
        'X:M:A', 'X:M:C', 'X:N:M', 'X:R:F', 'X:R:G', 'X:R:V', 'Y:D:E', 'Y:D:S',
        'Y:F:E', 'Y:G:B', 'Y:H:G', 'Y:H:K', 'Y:H:P', 'Y:I:J', 'Y:K:T', 'Y:L:D',
        'Y:L:K', 'Y:L:S', 'Y:L:W', 'Y:O:G', 'Y:U:B', 'Y:U:Z', 'Y:X:K', 'Y:Y:B',
        'Y:Y:E', 'Z:B:G', 'Z:C:G', 'Z:C:P', 'Z:D:G', 'Z:D:L', 'Z:F:A', 'Z:F:B',
        'Z:F:I', 'Z:F:U', 'Z:G:B', 'Z:G:W', 'Z:H:B', 'Z:K:C', 'Z:L:G', 'Z:M:C',
        'Z:M:D', 'Z:Y:C',
    ];

    /**
     * Doublons de contenu CROISÉS avec une famille EXTÉRIEURE à l'axe commençant+terminant+avec
     * (D-041, garde-fou structurel demandé par le constat C-4 du 4e audit consolidé,
     * docs/DECISIONS.md D-040) -- distinct de DUPLICATE_CONTENT_KEYS/SIBLING_DUPLICATE_KEYS/
     * CROSS_DUPLICATE_LENGTH_KEYS ci-dessus (qui comparent uniquement au sein du même panier
     * commençant+terminant, ou avec la variante longueur du MÊME panier) : ici, une page
     * "commençant/{X}/terminant/{Y}/avec/{Z}" partage un contenu strictement identique avec une
     * page d'une famille SANS RAPPORT au panier commençant+terminant d'origine (terminant ou
     * commençant multi-lettres portant un préfixe/suffixe totalement différent, avec à deux
     * lettres...), trouvée par le balayage GÉNÉRIQUE de tout le registre
     * (scripts/check_combinatorial_duplicates.php, balayage du 2026-08-21 : 1 656 groupes,
     * 2 089 pages en excès).
     *
     * Règle de départage : App\Search\DuplicatePageResolver::resolveDuplicateWinner() -- une page
     * "commençant/{X}/terminant/{Y}/avec/{Z}" a TOUJOURS 3 composants. Les 314 clés se répartissent
     * en pertes face à terminant multi-lettres (248), commençant multi-lettres (65) et avec à deux
     * lettres, palier 2 (1 -- cas non structurel, comparable à celui déjà trouvé sur le palier 3 de
     * "avec", voir AvecThreeLettersLinksBuilder::EXTERNAL_DUPLICATE_KEYS).
     *
     * 314 clés (format "{début}:{fin}:{lettre}"), recalculées indépendamment par échantillonnage
     * direct contre `terms` (voir le rapport AFTER de cette tâche) : 0 divergence -- à 838 180
     * termes.
     *
     * Liste figée : valable pour l'état actuel de storage/dictionary_fr.sqlite au moment de sa
     * construction (838 180 termes, D-022 à D-048). PAS REVALIDÉE par le passage à 844 961 termes
     * (D-051/D-052, 2026-09-02) : contrairement à App\Search\PrefixAvecLinksBuilder::
     * EXTERNAL_DUPLICATE_KEYS (4 clés, adversaires nommés individuellement), ces 338 clés n'ont
     * jamais eu leur adversaire exact consigné ici -- seul le résultat du balayage générique
     * (scripts/check_combinatorial_duplicates.php contre storage/seo_fr.sqlite) l'identifie. Cette
     * liste dépend donc du registre SEO complet, reconstruit et revalidé séparément (hors périmètre
     * data-engine à ce stade). Choix délibéré (même raisonnement que partout ailleurs sur cette
     * série de correctifs) : laisser une clé DEDANS à tort coûte au plus quelques liens manqués, en
     * retirer une à tort réintroduirait un lien vivant vers une page potentiellement noindex
     * (violation R5) -- liste INCHANGÉE ici, à revalider par un balayage générique complet dès que
     * le registre sera reconstruit.
     *
     * COMPLÉTÉE PAR D-047 (2026-08-31, balayage générique post-D-045/D-046) : +24 clés
     * supplémentaires (extraites directement du lot storage/seo_fr.sqlite/word_list_combined_
     * with_letter déjà appliqué au registre) -- 338 au total. Découverte tardive : ce lot avait
     * été appliqué au registre réel dès sa découverte mais laissait ce builder générer des liens
     * internes VIVANTS vers ces pages devenues noindex,follow (violation R5, confirmée en direct
     * via HTTP avant correctif : /mots/commencant/a/terminant/c liait vers
     * /mots/commencant/a/terminant/c/avec/f, noindex depuis D-047). Voir
     * docs/DECISIONS.md D-047/D-048.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'A:B:I', 'A:C:F', 'A:C:V', 'A:D:K', 'A:H:W', 'A:M:J', 'A:M:K', 'A:O:W',
        'A:O:X', 'A:P:K', 'A:R:W', 'A:U:K', 'A:Y:S', 'B:C:H', 'B:F:D', 'B:F:H',
        'B:F:M', 'B:F:X', 'B:G:Z', 'B:H:M', 'B:H:Z', 'B:I:W', 'B:L:W', 'B:M:F',
        'B:M:G', 'B:O:Y', 'B:O:Z', 'B:P:K', 'B:P:U', 'B:P:Z', 'B:Q:E', 'B:Q:I',
        'B:Y:H', 'B:Y:S', 'B:Y:T', 'B:Y:Z', 'C:C:Z', 'C:H:M', 'C:O:F', 'C:P:G',
        'C:V:A', 'C:V:O', 'C:W:E', 'C:W:R', 'C:X:W', 'C:Y:K', 'C:Y:W', 'D:C:J',
        'D:D:K', 'D:H:G', 'D:M:Q', 'D:M:Z', 'D:O:K', 'D:O:X', 'D:P:C', 'D:P:G',
        'D:P:H', 'D:P:I', 'D:P:M', 'D:P:N', 'D:V:E', 'D:V:I', 'D:Y:G', 'D:Y:Z',
        'E:C:B', 'E:C:J', 'E:F:B', 'E:G:C', 'E:M:K', 'E:M:V', 'E:O:F', 'E:O:K',
        'E:U:Y', 'E:Y:D', 'E:Y:I', 'F:C:P', 'F:C:V', 'F:H:Y', 'F:L:J', 'F:M:H',
        'F:M:X', 'F:Y:C', 'F:Y:D', 'F:Y:H', 'F:Y:M', 'F:Y:W', 'G:D:K', 'G:F:P',
        'G:G:U', 'G:K:B', 'G:K:D', 'G:L:Z', 'G:O:J', 'G:P:S', 'G:Y:M', 'G:Y:S',
        'G:Y:U', 'G:Y:W', 'G:Y:Z', 'H:A:W', 'H:B:U', 'H:C:K', 'H:H:V', 'H:H:W',
        'H:K:M', 'H:O:B', 'H:O:P', 'H:P:E', 'H:P:T', 'H:U:K', 'H:Y:P', 'H:Y:S',
        'I:C:E', 'I:K:A', 'I:N:Z', 'I:O:V', 'I:O:Z', 'I:U:J', 'I:Y:X', 'J:D:G',
        'J:D:W', 'J:I:W', 'J:K:N', 'J:M:N', 'J:O:K', 'J:P:M', 'J:X:K', 'J:Y:Z',
        'K:B:N', 'K:C:U', 'K:D:B', 'K:F:D', 'K:F:W', 'K:H:F', 'K:H:Z', 'K:I:V',
        'K:K:F', 'K:L:W', 'K:N:X', 'K:O:P', 'K:O:Y', 'K:P:O', 'K:U:F', 'K:U:V',
        'K:X:S', 'K:Z:G', 'L:C:F', 'L:C:P', 'L:C:Y', 'L:G:Y', 'L:H:D', 'L:H:R',
        'L:H:S', 'L:I:J', 'L:L:Q', 'L:L:W', 'L:M:J', 'L:M:K', 'L:O:X', 'L:O:Y',
        'L:R:W', 'L:U:Z', 'M:A:W', 'M:B:N', 'M:C:J', 'M:F:J', 'M:G:J', 'M:H:Z',
        'M:K:D', 'M:K:J', 'M:L:W', 'M:M:Q', 'M:Y:X', 'N:B:Q', 'N:C:U', 'N:D:J',
        'N:K:Y', 'N:L:X', 'N:L:Z', 'N:M:G', 'N:M:Z', 'N:O:J', 'N:O:K', 'N:O:Y',
        'N:Q:D', 'N:Q:E', 'N:U:C', 'N:U:J', 'N:W:G', 'N:W:K', 'O:A:W', 'O:D:Z',
        'O:F:G', 'O:H:A', 'O:K:G', 'O:K:M', 'O:K:Y', 'O:O:B', 'O:O:D', 'O:O:P',
        'O:U:K', 'P:A:W', 'P:B:C', 'P:B:I', 'P:F:K', 'P:F:Q', 'P:H:R', 'P:K:J',
        'P:L:Z', 'P:O:J', 'P:O:X', 'P:P:M', 'P:Y:S', 'P:Y:X', 'Q:A:K', 'Q:E:W',
        'Q:F:Z', 'Q:H:O', 'Q:K:I', 'Q:L:Z', 'Q:N:W', 'Q:O:B', 'Q:O:K', 'Q:Y:C',
        'R:B:G', 'R:B:H', 'R:C:Y', 'R:D:Y', 'R:G:H', 'R:H:K', 'R:H:N', 'R:H:P',
        'R:K:D', 'R:L:K', 'R:L:Z', 'R:M:Q', 'R:N:K', 'R:O:F', 'R:P:S', 'R:U:K',
        'R:V:D', 'R:V:T', 'R:Y:S', 'R:Y:W', 'S:B:D', 'S:B:K', 'S:G:J', 'S:H:Q',
        'S:H:Y', 'S:K:F', 'S:K:J', 'S:M:F', 'S:M:K', 'S:M:V', 'S:O:Q', 'S:P:D',
        'S:P:G', 'S:P:W', 'S:Y:C', 'S:Y:D', 'S:Y:V', 'S:Y:Z', 'T:B:I', 'T:D:W',
        'T:D:X', 'T:H:M', 'T:K:D', 'T:K:G', 'T:K:S', 'T:O:Y', 'T:R:Z', 'T:U:K',
        'T:V:A', 'T:V:G', 'T:Y:G', 'T:Y:K', 'T:Y:X', 'U:G:C', 'U:H:G', 'U:H:M',
        'U:M:H', 'U:O:A', 'U:U:B', 'U:U:D', 'U:U:N', 'U:U:S', 'V:F:B', 'V:L:X',
        'V:M:B', 'V:O:D', 'V:P:A', 'V:U:Y', 'V:Y:T', 'V:Y:Z', 'W:A:U', 'W:D:N',
        'W:F:H', 'W:H:A', 'W:H:L', 'W:M:B', 'W:M:D', 'W:M:G', 'W:M:K', 'W:M:P',
        'W:P:I', 'W:U:K', 'W:Y:B', 'W:Y:E', 'X:L:M', 'Y:A:Z', 'Y:H:A', 'Y:I:Q',
        'Y:I:V', 'Y:I:Z', 'Y:K:L', 'Y:M:O', 'Y:O:E', 'Y:R:G', 'Z:B:A', 'Z:B:M',
        'Z:B:U', 'Z:C:E', 'Z:C:U', 'Z:G:R', 'Z:H:D', 'Z:H:N', 'Z:H:W', 'Z:K:D',
        'Z:K:P', 'Z:L:Y', 'Z:N:F', 'Z:O:S', 'Z:P:I', 'Z:P:U', 'Z:U:K', 'Z:U:N',
        'Z:Y:E', 'Z:Y:T',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $startLetter, string $endLetter): StartEndWithLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'start_end_with' AND list_key LIKE ?"
        );
        $statement->execute([$startLetter . ':' . $endLetter . ':%']);

        // URL de la page parente (commencant+terminant, sans "avec") : sert a detecter les
        // lettres "avec" degenerees (D-032, WordListFilters::fromPath() collapse silencieusement
        // "avec/X" quand X est deja garanti par un commencant/terminant d'une seule lettre --
        // meme mecanisme que le collapse "position" deja etabli, D-023). Sans cette detection,
        // ces lettres degenerees (toujours PRESENTES dans list_counts : count_chars() du script
        // de precalcul liste la lettre de debut et la lettre de fin comme des lettres
        // "distinctes" du mot au meme titre que les autres, aucune exclusion cote precalcul)
        // produiraient un lien dont l'URL est IDENTIQUE a celle de la page source elle-meme --
        // un doublon trompeur (deux lettres "avec" differentes menant chacune vers la MEME URL
        // que la page qui les propose), pas seulement une page en moins.
        $parentUrl = WordListFilters::fromPath(
            'commencant/' . strtolower($startLetter) . '/terminant/' . strtolower($endLetter)
        )?->canonicalUrl();

        self::$duplicateContentKeySet ??= array_flip(self::DUPLICATE_CONTENT_KEYS);
        self::$siblingDuplicateKeySet ??= array_flip(self::SIBLING_DUPLICATE_KEYS);
        self::$crossDuplicateLengthKeySet ??= array_flip(self::CROSS_DUPLICATE_LENGTH_KEYS);
        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);

        $links = [];

        foreach ($statement as $row) {
            $parts = explode(':', (string) $row['list_key'], 3);
            $letter = $parts[2];
            $count = (int) $row['count'];

            $path = 'commencant/' . strtolower($startLetter) . '/terminant/' . strtolower($endLetter)
                . '/avec/' . strtolower($letter);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url === null || $url === $parentUrl) {
                continue;
            }

            // Doublon de CONTENU (audit consolide, NO GO) : URL distincte de la page parente,
            // mais tous les mots de la paire contiennent deja cette lettre -- voir
            // DUPLICATE_CONTENT_KEYS ci-dessus, jamais un lien vers une page dont le contenu est
            // identique a une page deja indexee.
            $key = strtoupper($startLetter) . ':' . strtoupper($endLetter) . ':' . strtoupper($letter);

            if (isset(self::$duplicateContentKeySet[$key])) {
                continue;
            }

            // Doublon de CONTENU entre pages SOEURS (I-A, 2e audit consolide) : une AUTRE lettre
            // "avec" du MEME panier produit exactement le meme sous-ensemble de mots -- voir
            // SIBLING_DUPLICATE_KEYS ci-dessus. La lettre alphabetiquement la plus petite du
            // groupe reste candidate (jamais exclue par ce filtre) ; les autres sont retirees ici.
            if (isset(self::$siblingDuplicateKeySet[$key])) {
                continue;
            }

            // Doublon de contenu CROISE avec l'AUTRE famille (3e audit consolide) : la tranche
            // LONGUEUR de ce meme panier {debut}:{fin} (App\Search\LengthLinksBuilder::byStartEnd)
            // contient EXACTEMENT le meme ensemble de mots -- voir CROSS_DUPLICATE_LENGTH_KEYS
            // ci-dessus. La variante LONGUEUR reste candidate, celle-ci (avec) est retiree.
            if (isset(self::$crossDuplicateLengthKeySet[$key])) {
                continue;
            }

            // Doublon de contenu CROISE avec une famille EXTERIEURE au panier commencant+
            // terminant d'origine (D-041) : voir EXTERNAL_DUPLICATE_KEYS ci-dessus.
            if (isset(self::$externalDuplicateKeySet[$key])) {
                continue;
            }

            $links[] = ['letter' => $letter, 'url' => $url, 'count' => $count];
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new StartEndWithLinks(links: $links, queryCount: 1);
    }
}
