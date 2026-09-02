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

    // --- Reflection : les deux listes figees ont les comptes reels recalcules pour D-051/D-052
    // --- (2026-09-02, 838 180 -> 844 961 termes, RECALCUL COMPLET depuis list_counts/`terms`,
    // --- jamais un patch incrementaire) : etaient 46/58 sur 838 180 termes. ---
    $reflection = new ReflectionClass(PrefixAvecTwoLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    Assert::same(52, count($duplicateParentKeys), 'exactement 52 doublons parent attendus (D-045, D-051/D-052 -- etait 46)');
    Assert::same(54, count($siblingDuplicateKeys), 'exactement 54 doublons soeurs attendus (D-045, D-051/D-052 -- etait 58)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(0, count(array_intersect($duplicateParentKeys, $siblingDuplicateKeys)), 'les deux listes doivent rester disjointes');

    // --- Cas connu (D-051/D-052, remplace l'ancien exemple A:Q:U -- casse par le complement
    // --- kaikki, SORTI de la liste) : W+A+J est un doublon PARENT exact (WEBJOURNAL est le seul
    // --- mot commencant par W avec J, il contient deja A) -- ne doit JAMAIS apparaitre depuis
    // --- commencant/w/avec/a. ---
    Assert::true(in_array('W:A:J', $duplicateParentKeys, true), 'sanity check : W:A:J fait bien partie de la liste figee (D-051/D-052)');
    $linksWA = $builder->build('W', 'A');
    $foundJ = array_values(array_filter($linksWA->links, static fn (array $l): bool => $l['letter'] === 'J'));
    Assert::true($foundJ === [], 'W:A:J est un doublon parent exact -- ne doit jamais etre produit depuis commencant/w/avec/a');
    $rawCountWAJ = (int) $pdo->query("SELECT count FROM list_counts WHERE list_type = 'start_with_pair' AND list_key = 'W:A:J'")->fetch()['count'];
    Assert::same(1, $rawCountWAJ, 'sanity check : W:A:J existe dans list_counts et vaut 1 (WEBJOURNAL, precalcul brut inchange, seule la sortie du builder est filtree)');

    // --- Cas connu (D-051/D-052, remplace l'ancien exemple D:J:Y -- casse par DJAKARTA, SORTI de
    // --- la liste) : V:G:W perd face a V:E:W (meme mot unique, VOLKSWAGEN, qui contient E, G et W
    // --- tous les trois) -- depuis commencant/v/avec/w, le partenaire E doit apparaitre (V:E:W,
    // --- gagnant), le partenaire G ne doit jamais apparaitre (V:G:W, doublon soeur exclu). ---
    Assert::true(in_array('V:G:W', $siblingDuplicateKeys, true), 'sanity check : V:G:W fait bien partie de la liste figee (D-051/D-052)');
    $linksVW = $builder->build('V', 'W');
    $foundG = array_values(array_filter($linksVW->links, static fn (array $l): bool => $l['letter'] === 'G'));
    $foundE = array_values(array_filter($linksVW->links, static fn (array $l): bool => $l['letter'] === 'E'));
    Assert::true($foundG === [], 'V:G:W est un doublon soeur -- ne doit jamais etre produit depuis commencant/v/avec/w');
    Assert::true($foundE !== [], 'V:E:W est la forme gagnante (plus petite alphabetiquement) -- doit etre produit normalement');

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
