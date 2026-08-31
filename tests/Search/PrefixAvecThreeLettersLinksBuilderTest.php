<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\PrefixAvecThreeLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\PrefixAvecThreeLettersLinksBuilder (D-045, palier 3 de l'extension de Family::
 * WORD_LIST_COMMENCANT_WITH_LETTER) : depuis "commencant/{X}/avec/{Y}/{Z}" (deja indexee,
 * palier 2), liens vers chaque variante "commencant/{X}/avec/{Y}/{Z}/{W}" qui a au moins un
 * resultat -- lu depuis list_counts (list_type 'start_with_triple'), jamais un calcul sur
 * `terms` au runtime.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new PrefixAvecThreeLettersLinksBuilder($connection);

    // --- Cas representatif verifie par force brute : commencant/A/avec/B/C. ---
    $linksABC = $builder->build('A', 'B', 'C');
    Assert::same(1, $linksABC->queryCount);
    Assert::true($linksABC->links !== [], 'sanity check : des mots commencant par A avec B, C et une 4e lettre existent');

    foreach ($linksABC->links as $link) {
        Assert::true(!in_array($link['letter'], ['A', 'B', 'C'], true), 'la lettre produite ne doit jamais etre le prefixe ni les lettres source');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM terms WHERE normalized LIKE 'A%' AND instr(normalized, 'B') > 0 AND instr(normalized, 'C') > 0 AND instr(normalized, ?) > 0"
        );
        $stmt->execute([$link['letter']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "A+B+C+{$link['letter']} : verifie par force brute");

        $expectedLetters = ['B', 'C', $link['letter']];
        sort($expectedLetters);
        Assert::same(
            '/mots/commencant/a/avec/' . strtolower($expectedLetters[0]) . '/' . strtolower($expectedLetters[1]) . '/' . strtolower($expectedLetters[2]),
            $link['url']
        );
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksABC->links); $i++) {
        Assert::true($linksABC->links[$i - 1]['letter'] < $linksABC->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Reflection : les deux listes figees ont les comptes reels calcules pour D-045. ---
    $reflection = new ReflectionClass(PrefixAvecThreeLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    Assert::same(3810, count($duplicateParentKeys), 'exactement 3810 doublons parent attendus (D-045)');
    Assert::same(1909, count($siblingDuplicateKeys), 'exactement 1909 doublons soeurs attendus (D-045)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(0, count(array_intersect($duplicateParentKeys, $siblingDuplicateKeys)), 'les deux listes doivent rester disjointes');

    // --- Cas connu, verifie manuellement (rapport de tache D-045) : A:B:Q:U est un doublon
    // --- PARENT exact -- ne doit jamais apparaitre depuis commencant/a/avec/b/q. ---
    Assert::true(in_array('A:B:Q:U', $duplicateParentKeys, true), 'sanity check : A:B:Q:U fait bien partie de la liste figee');
    $linksABQ = $builder->build('A', 'B', 'Q');
    $foundU = array_values(array_filter($linksABQ->links, static fn (array $l): bool => $l['letter'] === 'U'));
    Assert::true($foundU === [], 'A:B:Q:U est un doublon parent exact (D-045) -- ne doit jamais etre produit');

    // --- Toutes les cles des deux listes figees respectent le format "{prefixe}:{l1}:{l2}:{l3}"
    // --- avec l1<l2<l3, et aucune ne contient le prefixe lui-meme (deja exclu au precalcul). ---
    foreach (array_merge($duplicateParentKeys, $siblingDuplicateKeys) as $key) {
        $parts = explode(':', $key);
        Assert::same(4, count($parts), "format de cle invalide : {$key}");
        [$prefix, $l1, $l2, $l3] = $parts;
        Assert::true($l1 < $l2 && $l2 < $l3, "lettres non ordonnees dans la cle figee : {$key}");
        Assert::true($prefix !== $l1 && $prefix !== $l2 && $prefix !== $l3, "cle degeneree (D-032) trouvee dans une liste figee : {$key}");
    }

    // --- R5 sur quelques triplets representatifs (pas exhaustif -- deja couvert au niveau du
    // --- generateur de lot, voir docs/DECISIONS.md D-045). ---
    foreach ([['A', 'B', 'D'], ['M', 'N', 'O'], ['Z', 'A', 'B']] as [$prefix, $l1, $l2]) {
        $links = $builder->build($prefix, $l1, $l2);
        foreach ($links->links as $link) {
            Assert::true($link['count'] > 0, "R5 : aucune entree a 0 attendue ({$prefix}:{$l1}:{$l2}:{$link['letter']})");
        }
    }
};
