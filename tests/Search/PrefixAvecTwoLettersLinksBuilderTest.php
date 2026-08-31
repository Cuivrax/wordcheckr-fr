<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\PrefixAvecTwoLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\PrefixAvecTwoLettersLinksBuilder (D-045, palier 2 de l'extension de Family::
 * WORD_LIST_COMMENCANT_WITH_LETTER) : depuis "commencant/{X}/avec/{Y}" (deja indexee, palier 1),
 * liens vers chaque variante "commencant/{X}/avec/{Y}/{Z}" qui a au moins un resultat -- lu
 * depuis list_counts (list_type 'start_with_pair'), jamais un calcul sur `terms` au runtime.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new PrefixAvecTwoLettersLinksBuilder($connection);

    // --- Cas representatif verifie par force brute : commencant/A/avec/B. ---
    $linksAB = $builder->build('A', 'B');
    Assert::same(1, $linksAB->queryCount);
    Assert::true($linksAB->links !== [], 'sanity check : des mots commencant par A avec B et une 3e lettre existent');

    foreach ($linksAB->links as $link) {
        Assert::true(!in_array($link['letter'], ['A', 'B'], true), 'la lettre produite ne doit jamais etre le prefixe ni la lettre source');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM terms WHERE normalized LIKE 'A%' AND instr(normalized, 'B') > 0 AND instr(normalized, ?) > 0"
        );
        $stmt->execute([$link['letter']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "A+B+{$link['letter']} : verifie par force brute");

        $expectedLetters = ['B', $link['letter']];
        sort($expectedLetters);
        Assert::same('/mots/commencant/a/avec/' . strtolower($expectedLetters[0]) . '/' . strtolower($expectedLetters[1]), $link['url']);
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksAB->links); $i++) {
        Assert::true($linksAB->links[$i - 1]['letter'] < $linksAB->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Reflection : les deux listes figees ont les comptes reels calcules pour D-045. ---
    $reflection = new ReflectionClass(PrefixAvecTwoLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    Assert::same(46, count($duplicateParentKeys), 'exactement 46 doublons parent attendus (D-045)');
    Assert::same(58, count($siblingDuplicateKeys), 'exactement 58 doublons soeurs attendus (D-045)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(0, count(array_intersect($duplicateParentKeys, $siblingDuplicateKeys)), 'les deux listes doivent rester disjointes');

    // --- Cas connu, verifie manuellement (rapport de tache D-045) : A+Q+U est un doublon PARENT
    // --- exact (tous les mots de A+Q contiennent deja U) -- ne doit JAMAIS apparaitre depuis
    // --- commencant/a/avec/q. ---
    Assert::true(in_array('A:Q:U', $duplicateParentKeys, true), 'sanity check : A:Q:U fait bien partie de la liste figee');
    $linksAQ = $builder->build('A', 'Q');
    $foundU = array_values(array_filter($linksAQ->links, static fn (array $l): bool => $l['letter'] === 'U'));
    Assert::true($foundU === [], 'A:Q:U est un doublon parent exact (D-045) -- ne doit jamais etre produit depuis commencant/a/avec/q');
    $rawCountAQU = (int) $pdo->query("SELECT count FROM list_counts WHERE list_type = 'start_with_pair' AND list_key = 'A:Q:U'")->fetch()['count'];
    Assert::true($rawCountAQU > 0, 'sanity check : A:Q:U existe bien dans list_counts (precalcul brut inchange, seule la sortie du builder est filtree)');

    // --- Cas connu, verifie manuellement : D:J:Y perd face a D:J:K (meme panier exact,
    // --- DJERMAKOYE/DJERMAKOYES) -- D:J:Y ne doit jamais apparaitre depuis commencant/d/avec/j. ---
    Assert::true(in_array('D:J:Y', $siblingDuplicateKeys, true), 'sanity check : D:J:Y fait bien partie de la liste figee');
    $linksDJ = $builder->build('D', 'J');
    $foundY = array_values(array_filter($linksDJ->links, static fn (array $l): bool => $l['letter'] === 'Y'));
    $foundK = array_values(array_filter($linksDJ->links, static fn (array $l): bool => $l['letter'] === 'K'));
    Assert::true($foundY === [], 'D:J:Y est un doublon soeur (D-045) -- ne doit jamais etre produit');
    Assert::true($foundK !== [], 'D:J:K est la forme gagnante (D-045) -- doit etre produit normalement');

    // --- Somme des comptes = total du palier 1 source, MOINS les extensions exclues, sur
    // --- quelques prefixes representatifs (pas les 26, pour rester rapide -- l'exhaustivite
    // --- complete est deja couverte au niveau du generateur de lot, voir docs/DECISIONS.md D-045). ---
    foreach (['A', 'D', 'M'] as $prefix) {
        $letters = $pdo->query("SELECT list_key FROM list_counts WHERE list_type = 'start_with' AND list_key LIKE '{$prefix}:%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($letters as $key) {
            [, $letter] = explode(':', $key, 2);
            $links = $builder->build($prefix, $letter);
            foreach ($links->links as $link) {
                Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$prefix}:{$letter}:{$link['letter']})");
            }
        }
    }
};
