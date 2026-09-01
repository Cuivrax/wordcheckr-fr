<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\AvecFourLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\AvecFourLettersLinksBuilder (D-048, palier 4 de l'ouverture en entonnoir de
 * "avec") : maillage "avec {X} {Y} {Z}" (palier 3) -> "avec {W} {X} {Y} {Z}" (palier 4) -- lu
 * depuis list_counts (list_type 'length_with_quad', precalcule par
 * scripts/build_explore_hub_counts.php), jamais un calcul sur `terms` au runtime. Verifie par
 * force brute sur la vraie base (lecture seule), meme methodologie que
 * AvecThreeLettersLinksBuilderTest.php -- etendue aux QUATRE positions possibles du triplet
 * source dans le quadruplet trie stocke (contre trois pour le palier 3).
 *
 * CORRECTIF (verification independante en 4 passes avant application registre/sitemap, D-048,
 * 2026-08-31) : le premier calcul de DUPLICATE_PARENT_KEYS construisait le canonical_path
 * TOUJOURS depuis les trois premieres lettres saisies (l1,l2,l3), quel que soit le sous-triplet
 * qui avait REELLEMENT le meme compte que le quadruplet -- verifie directement sur pieces :
 * "10:A:B:H:W" (compte reel = 3) avait ete associe a "10:A:B:H" (compte reel = 1285, AUCUN
 * rapport), le vrai sous-triplet correspondant etait "10:B:H:W" (compte = 3). Corrige (voir
 * ci-dessous, cas B:H:W). Trois correctifs supplementaires en cascade, tous verifies par force
 * brute avant application : (2) chaine de canonical resolue jusqu'a une page reellement
 * index,follow (un triplet palier 3 peut lui-meme avoir ete elimine par un correctif anterieur,
 * D-040/D-047, et donc etre absent du registre ou lui-meme noindex) ; (3) pour les quadruplets
 * dont le triplet parent historique n'a AUCUN representant indexe nulle part (cas rarissime,
 * compte tres petit), resolution par empreinte reelle directe contre les pages palier 1/2/3
 * actuellement index,follow, ou promotion en index,follow si aucun representant n'existe ; (4)
 * les quadruplets ainsi promus n'avaient JAMAIS ete compares ENTRE EUX (chacun ecarte
 * independamment du groupe de comparaison "soeurs" au moment ou il avait ete classe -- a tort --
 * comme doublon-parent) -- verifie directement sur pieces : "10:A:B:J:W" et "10:A:E:J:W"
 * partagent la MEME empreinte reelle exacte (WEBJOURNAL, seul mot des deux), la seconde
 * corrigee pour pointer vers la premiere plutot que d'etre promue en doublon d'elle-meme.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new AvecFourLettersLinksBuilder($connection);

    // --- Triplet "au milieu de l'alphabet" (I, N, S) : doit trouver des partenaires dans les
    // --- QUATRE zones possibles -- avant I, entre I et N, entre N et S, apres S -- preuve
    // --- directe que les quatre motifs LIKE de la requete OR couvrent bien les quatre positions
    // --- possibles du quadruplet stocke (list_key = "{len}:{l1}:{l2}:{l3}:{l4}", l1<l2<l3<l4
    // --- alphabetiquement -- une seule ligne par quadruplet non ordonne). ---
    $links = $builder->build(9, 'I', 'N', 'S');
    Assert::same(1, $links->queryCount, 'une seule requete triviale sur list_counts');

    // --- Chaque lien verifie par force brute (instr() quadruple), plus l'URL canonique
    // --- attendue (toujours l1<l2<l3<l4 dans l'URL). ---
    foreach ($links->links as $link) {
        $letters = ['I', 'N', 'S', $link['letter']];
        sort($letters, SORT_STRING);

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE length = 9 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0 AND instr(normalized, ?) > 0');
        $stmt->execute(['I', 'N', 'S', $link['letter']]);
        $expected = (int) $stmt->fetch()['c'];

        Assert::same($expected, $link['count'], "9-lettres avec I, N, S et {$link['letter']} : verifie par force brute");
        Assert::same('/mots/9-lettres/avec/' . strtolower($letters[0]) . '/' . strtolower($letters[1]) . '/' . strtolower($letters[2]) . '/' . strtolower($letters[3]), $link['url']);
        Assert::true(!in_array($link['letter'], ['I', 'N', 'S'], true), 'le partenaire ne doit jamais etre une des trois lettres source');
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    // --- Tri alphabetique par lettre partenaire. ---
    for ($i = 1; $i < count($links->links); $i++) {
        Assert::true($links->links[$i - 1]['letter'] < $links->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Ordre de saisie du triplet source sans effet (defense dans le builder). ---
    $linksReversedOrder = $builder->build(9, 'S', 'I', 'N');
    Assert::same($links->links, $linksReversedOrder->links, 'ordre de saisie sans effet sur le resultat');

    // ============================================================================================
    // CAS REGRESSION 1 (le bug trouve et corrige, D-048) : le quadruplet "10:A:B:H:W" (compte
    // reel = 3) est un doublon de contenu PARENT du triplet "10:B:H:W" (compte = 3), PAS de
    // "10:A:B:H" (compte = 1285, sans rapport). Depuis la page source B,H (palier 2) -- l'une des
    // trois paires du triplet gagnant B:H:W -- le builder ne doit JAMAIS produire A comme
    // partenaire menant a "avec/a/b/h/w" (exclu, DUPLICATE_PARENT_KEYS).
    // ============================================================================================
    // Le builder attend un TRIPLET source (palier 3) ; le cas regression porte sur le quadruplet
    // A:B:H:W lui-meme, verifie directement via la constante figee plutot que via build() ici.
    $reflection = new ReflectionClass(AvecFourLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    $externalDuplicateKeys = $reflection->getConstant('EXTERNAL_DUPLICATE_KEYS');

    Assert::true(in_array('10:A:B:H:W', $duplicateParentKeys, true), '10:A:B:H:W doit etre exclu (doublon de contenu, cas regression D-048)');

    $countBHW = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE length = 10 AND instr(normalized, 'B') > 0 AND instr(normalized, 'H') > 0 AND instr(normalized, 'W') > 0")->fetch()['c'];
    $countABHW = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE length = 10 AND instr(normalized, 'A') > 0 AND instr(normalized, 'B') > 0 AND instr(normalized, 'H') > 0 AND instr(normalized, 'W') > 0")->fetch()['c'];
    $countABH = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE length = 10 AND instr(normalized, 'A') > 0 AND instr(normalized, 'B') > 0 AND instr(normalized, 'H') > 0")->fetch()['c'];
    Assert::same(3, $countBHW, 'sanity check : 10:B:H:W = 3 mots reels');
    Assert::same(3, $countABHW, 'sanity check : 10:A:B:H:W = 3 mots reels (identique a B:H:W -- vrai doublon de contenu)');
    Assert::true($countABH !== $countABHW, 'sanity check : 10:A:B:H (1285) NE DOIT PAS correspondre a 10:A:B:H:W (3) -- preuve du bug initial corrige');

    // ============================================================================================
    // CAS REGRESSION 2 (doublons-soeurs entre pages "promues", jamais comparees entre elles avant
    // le 4e correctif, D-048) : "10:A:B:J:W" et "10:A:E:J:W" partagent la MEME empreinte reelle
    // exacte (WEBJOURNAL, seul mot des deux) -- un seul des deux doit rester index,follow.
    // ============================================================================================
    Assert::true(!in_array('10:A:B:J:W', $duplicateParentKeys, true) && !in_array('10:A:B:J:W', $siblingDuplicateKeys, true), '10:A:B:J:W doit rester eligible (gagnant du groupe WEBJOURNAL, cas regression D-048)');
    Assert::true(in_array('10:A:E:J:W', $siblingDuplicateKeys, true), '10:A:E:J:W doit etre exclu (doublon SOEUR du groupe WEBJOURNAL, cas regression D-048)');

    $wordsABJW = (string) $pdo->query("SELECT GROUP_CONCAT(normalized) FROM terms WHERE length = 10 AND instr(normalized,'A')>0 AND instr(normalized,'B')>0 AND instr(normalized,'J')>0 AND instr(normalized,'W')>0")->fetchColumn();
    $wordsAEJW = (string) $pdo->query("SELECT GROUP_CONCAT(normalized) FROM terms WHERE length = 10 AND instr(normalized,'A')>0 AND instr(normalized,'E')>0 AND instr(normalized,'J')>0 AND instr(normalized,'W')>0")->fetchColumn();
    Assert::same('WEBJOURNAL', $wordsABJW, 'sanity check : A,B,J,W (10 lettres) = WEBJOURNAL, unique mot');
    Assert::same('WEBJOURNAL', $wordsAEJW, 'sanity check : A,E,J,W (10 lettres) = WEBJOURNAL, meme unique mot -- vrai doublon soeur');

    // ============================================================================================
    // Coherence structurelle des trois constantes figees.
    // ============================================================================================
    Assert::same(10185, count($duplicateParentKeys), 'exactement 10 185 quadruplets doublons de contenu PARENT attendus (D-048)');
    Assert::same(4145, count($siblingDuplicateKeys), 'exactement 4 145 quadruplets doublons de contenu SOEUR attendus (D-048)');
    Assert::same(0, count($externalDuplicateKeys), 'aucun doublon croise famille exterieure encore trouve pour le palier 4 (a completer si un futur balayage generique en trouve)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans DUPLICATE_PARENT_KEYS elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans SIBLING_DUPLICATE_KEYS elle-meme');
    $parentSet = array_fill_keys($duplicateParentKeys, true);
    $siblingSet = array_fill_keys($siblingDuplicateKeys, true);
    Assert::same(0, count(array_intersect_key($parentSet, $siblingSet)), 'DUPLICATE_PARENT_KEYS et SIBLING_DUPLICATE_KEYS doivent rester deux ensembles disjoints par construction');

    // ============================================================================================
    // Verification EXHAUSTIVE du maillage sur les 28 827 pages sources reelles du palier 3
    // (list_type 'length_with_triple', D-031) -- pas un echantillon, meme discipline que tous les
    // paliers precedents. Compare le vrai code (AvecFourLettersLinksBuilder, via list_counts) au
    // recalcul independant depuis list_counts lui-meme, dans les DEUX sens.
    // ============================================================================================
    $tripleAnchorsStatement = $pdo->query("SELECT list_key FROM list_counts WHERE list_type = 'length_with_triple'");
    $tripleAnchors = [];
    foreach ($tripleAnchorsStatement as $row) {
        $tripleAnchors[] = explode(':', (string) $row['list_key'], 4);
    }
    Assert::same(28827, count($tripleAnchors), 'sanity check : 28 827 pages palier 3 reelles (D-031)');

    $expectedQuad = [];
    foreach ($pdo->query("SELECT list_key, count FROM list_counts WHERE list_type = 'length_with_quad'") as $row) {
        $expectedQuad[(string) $row['list_key']] = (int) $row['count'];
    }
    Assert::same(123557, count($expectedQuad), 'sanity check : 123 557 lignes length_with_quad reelles (D-048)');

    $excludedKeysQuad = $parentSet + $siblingSet + array_fill_keys($externalDuplicateKeys, true);
    Assert::same(14330, count($excludedKeysQuad), 'sanity check : 14 330 exclusions au total (10 185 + 4 145 + 0)');

    $producedKeysQuad = [];
    $totalLinksProducedQuad = 0;

    foreach ($tripleAnchors as [$length, $x, $y, $z]) {
        $anchorLinks = $builder->build((int) $length, $x, $y, $z);
        Assert::same(1, $anchorLinks->queryCount, "queryCount doit rester 1 pour {$length}:{$x}:{$y}:{$z}");

        $previousLetter = null;
        foreach ($anchorLinks->links as $link) {
            $quad = [$x, $y, $z, $link['letter']];
            sort($quad, SORT_STRING);
            $key = $length . ':' . $quad[0] . ':' . $quad[1] . ':' . $quad[2] . ':' . $quad[3];

            Assert::true(isset($expectedQuad[$key]), "cle produite par le builder absente de list_counts : {$key}");
            Assert::true(!isset($excludedKeysQuad[$key]), "cle doublon de contenu produite par erreur, aurait du etre exclue : {$key}");
            Assert::same($expectedQuad[$key], $link['count'], "compte divergent pour {$key}");
            Assert::same(
                '/mots/' . $length . '-lettres/avec/' . strtolower($quad[0]) . '/' . strtolower($quad[1]) . '/' . strtolower($quad[2]) . '/' . strtolower($quad[3]),
                $link['url'],
                "URL divergente pour {$key}"
            );
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$key})");

            if ($previousLetter !== null) {
                Assert::true($previousLetter < $link['letter'], "ordre alphabetique attendu pour {$length}:{$x}:{$y}:{$z}");
            }
            $previousLetter = $link['letter'];

            if (!isset($producedKeysQuad[$key])) {
                $producedKeysQuad[$key] = true;
                $totalLinksProducedQuad++;
            }
        }
    }

    // Sens 1 -> 2 : chaque lien produit par le vrai code correspond a une ligne list_counts
    // eligible reelle (ni doublon PARENT ni doublon SOEUR ni doublon croise), sans exception.
    $expectedEligibleCountQuad = count($expectedQuad) - count($excludedKeysQuad);
    Assert::same(109227, $expectedEligibleCountQuad, 'sanity check : 109 227 lignes eligibles (123 557 brutes - 14 330 exclusions, D-048)');
    Assert::same($expectedEligibleCountQuad, $totalLinksProducedQuad, 'total des quadruplets distincts produits doit egaler les lignes list_counts length_with_quad eligibles');

    // Sens 2 -> 1 : chaque ligne list_counts length_with_quad eligible reelle est produite par le
    // builder depuis AU MOINS UNE de ses (jusqu'a 4) pages source reelles ; chaque ligne EXCLUE,
    // a l'inverse, n'est JAMAIS produite depuis AUCUNE de ses ancres.
    foreach (array_keys($expectedQuad) as $key) {
        if (isset($excludedKeysQuad[$key])) {
            Assert::true(!isset($producedKeysQuad[$key]), "ligne exclue (doublon de contenu) produite par erreur : {$key}");
        } else {
            Assert::true(isset($producedKeysQuad[$key]), "ligne list_counts eligible jamais produite par le builder depuis aucune de ses pages source : {$key}");
        }
    }
};
