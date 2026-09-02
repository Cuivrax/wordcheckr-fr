<?php

declare(strict_types=1);

use App\Config;
use App\Search\Normalizer;
use Tests\Support\Assert;

/**
 * scripts/apply_kaikki_supplement_word_rollout.php -- boite noire, un vrai sous-processus PHP,
 * JAMAIS contre les vrais storage/dictionary_fr.sqlite / storage/seo_fr.sqlite du depot :
 * SCRABBLE_DICTIONARY_DB_PATH, SCRABBLE_SEO_DB_PATH et SCRABBLE_KAIKKI_SUPPLEMENT_DIR
 * redirigent le script vers des fichiers temporaires propres a ce test, supprimes a la fin
 * (meme discipline que tests/Seo/BuildScriptsTest.php et
 * tests/Seo/CheckCombinatorialDuplicatesTest.php). SCRABBLE_KAIKKI_FRAGMENT_SIZE reduit le
 * plafond de fragment (40 000 en production) a une valeur minuscule pour exercer reellement
 * le rollover d'un fragment a l'autre sans construire des dizaines de milliers de lignes.
 *
 * Jeu de donnees synthetique : dictionnaire avec 3 formes non admises nouvelles (AALBORG,
 * ABERDEEN, ZANGI -- toutes trois presentes dans les CSV kaikki de fixture), 1 forme non
 * admise DEJA en registre (OLDNONADMIS, temoin negatif -- ne doit jamais etre reinseree ni
 * deplacee de fragment) et 1 forme ADMISE (POSER, is_ods8=1 -- ne doit jamais apparaitre dans
 * le delta, cette famille ne concerne que le non-admis).
 */
return function (): void {
    $root = __DIR__ . '/../..';
    $tmpDir = sys_get_temp_dir() . '/scrabble_kaikki_rollout_test_' . bin2hex(random_bytes(4));
    mkdir($tmpDir);
    mkdir($tmpDir . '/kaikki_supplement');

    $dictPath = $tmpDir . '/dictionary_fr.sqlite';
    $seoPath = $tmpDir . '/seo_fr.sqlite';
    $kaikkiDir = $tmpDir . '/kaikki_supplement';

    $tileScores = Config::load('fr')->tileScores;

    $buildDictionary = static function (array $words) use ($root, $dictPath, $tileScores): void {
        if (is_file($dictPath)) {
            unlink($dictPath);
        }

        $schemaSql = file_get_contents($root . '/schema.sql');
        Assert::true($schemaSql !== false, 'schema.sql introuvable');

        $pdo = new PDO('sqlite:' . $dictPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($schemaSql);

        $insert = $pdo->prepare(
            'INSERT INTO terms (display_term, normalized, is_french, is_ods8, is_ods9, is_admitted, score, length, signature, reversed, letter_mask) '
            . 'VALUES (?, ?, 1, ?, 0, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($words as [$word, $isOds8]) {
            $insert->execute([
                $word,
                $word,
                $isOds8,
                $isOds8,
                Normalizer::score($word, $tileScores),
                strlen($word),
                Normalizer::signature($word),
                Normalizer::reverse($word),
                Normalizer::letterMask($word),
            ]);
        }
    };

    $buildRegistry = static function (array $existingRows) use ($root, $seoPath): void {
        if (is_file($seoPath)) {
            unlink($seoPath);
        }

        $seoSchemaSql = file_get_contents($root . '/app/Seo/schema.sql');
        Assert::true($seoSchemaSql !== false, 'app/Seo/schema.sql introuvable');

        $pdo = new PDO('sqlite:' . $seoPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($seoSchemaSql);

        $insert = $pdo->prepare(
            'INSERT INTO registry (route_path, family, robots, canonical_path, sitemap_fragment, batch_id, result_count, notes, added_at) '
            . 'VALUES (?, ?, \'index,follow\', ?, ?, \'old-batch\', NULL, \'deja applique\', \'2026-08-04\')'
        );

        foreach ($existingRows as [$routePath, $family, $fragment]) {
            $insert->execute([$routePath, $family, $routePath, $fragment]);
        }
    };

    $writeKaikkiCsv = static function (string $fileName, array $header, array $rows) use ($kaikkiDir): void {
        $handle = fopen($kaikkiDir . '/' . $fileName, 'wb');
        Assert::true($handle !== false);
        fputcsv($handle, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fclose($handle);
    };

    $run = static function () use ($root, $dictPath, $seoPath, $kaikkiDir): array {
        $cmd = [PHP_BINARY, $root . '/scripts/apply_kaikki_supplement_word_rollout.php'];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(
            getenv() === false ? [] : getenv(),
            [
                'SCRABBLE_DICTIONARY_DB_PATH' => $dictPath,
                'SCRABBLE_SEO_DB_PATH' => $seoPath,
                'SCRABBLE_KAIKKI_SUPPLEMENT_DIR' => $kaikkiDir,
                'SCRABBLE_KAIKKI_FRAGMENT_SIZE' => '2',
            ],
        );

        $process = proc_open($cmd, $descriptors, $pipes, $root, $env);
        Assert::true($process !== false, 'impossible de lancer le script');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    };

    try {
        // ============================================================================
        // Scenario 1 : cas nominal -- delta de 3 formes (AALBORG, ABERDEEN, ZANGI),
        // rollover de fragment exerce (SCRABBLE_KAIKKI_FRAGMENT_SIZE=2, dernier fragment
        // deja a 1/2 avec OLDNONADMIS). POSER (admis) ne doit jamais apparaitre dans le delta.
        // ============================================================================
        $buildDictionary([
            ['AALBORG', 0],
            ['ABERDEEN', 0],
            ['ZANGI', 0],
            ['OLDNONADMIS', 0],
            ['POSER', 1],
        ]);
        $buildRegistry([
            ['/mot/oldnonadmis', 'word_french_not_admitted', 'invalid-french-0001'],
            ['/mot/poser', 'word_admitted', 'words-0001'],
        ]);
        $writeKaikkiCsv('names_definitions_final.csv', ['normalized', 'raw', 'definition_fr'], [
            ['AALBORG', 'Aalborg', 'Ville du nord du Danemark.'],
            ['ABERDEEN', 'Aberdeen', 'Ville portuaire d\'Ecosse.'],
        ]);
        $writeKaikkiCsv('register_definitions_final.csv', ['normalized', 'raw', 'tag', 'definition_fr'], [
            ['ZANGI', 'zangui', 'colloquial', 'Terme familier.'],
        ]);

        [$exitCode, $stdout, $stderr] = $run();
        Assert::same(0, $exitCode, "le script aurait du reussir : {$stderr}");
        Assert::true(str_contains($stdout, 'delta calcule : 3 formes nouvelles'), $stdout);
        Assert::true(str_contains($stdout, '3 ligne(s) inseree(s)'), $stdout);
        Assert::true(str_contains($stdout, 'controle croise : 100%'), $stdout);

        $pdo = new PDO('sqlite:' . $seoPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $familyCount = (int) $pdo->query("SELECT COUNT(*) c FROM registry WHERE family = 'word_french_not_admitted'")->fetch()['c'];
        Assert::same(4, $familyCount, '1 deja presente + 3 nouvelles');

        // OLDNONADMIS jamais deplacee ni retouchee.
        $old = $pdo->query("SELECT sitemap_fragment, batch_id FROM registry WHERE route_path = '/mot/oldnonadmis'")->fetch();
        Assert::same('invalid-french-0001', $old['sitemap_fragment']);
        Assert::same('old-batch', $old['batch_id']);

        // POSER (admis) totalement hors de portee de ce script.
        $poserCount = (int) $pdo->query("SELECT COUNT(*) c FROM registry WHERE route_path = '/mot/poser'")->fetch()['c'];
        Assert::same(1, $poserCount);
        $poserFamily = $pdo->query("SELECT family FROM registry WHERE route_path = '/mot/poser'")->fetch()['family'];
        Assert::same('word_admitted', $poserFamily);

        // AALBORG complete le fragment 0001 (1 -> 2, plafond de test = 2) ; ABERDEEN et ZANGI
        // (ordre alphabetique) ouvrent le fragment 0002.
        $aalborg = $pdo->query("SELECT robots, canonical_path, sitemap_fragment, notes, added_at, batch_id FROM registry WHERE route_path = '/mot/aalborg'")->fetch();
        Assert::same('index,follow', $aalborg['robots']);
        Assert::same('/mot/aalborg', $aalborg['canonical_path'], 'R3 : canonical_path = route_path');
        Assert::same('invalid-french-0001', $aalborg['sitemap_fragment']);
        Assert::true($aalborg['notes'] !== '' && $aalborg['notes'] !== null, 'R7 : note non vide');
        Assert::true(str_contains($aalborg['notes'], 'D-051'), 'note doit referencer D-051');
        Assert::true(str_starts_with($aalborg['batch_id'], 'word_french_not_admitted-kaikki-supplement-'));

        $aberdeen = $pdo->query("SELECT sitemap_fragment FROM registry WHERE route_path = '/mot/aberdeen'")->fetch();
        Assert::same('invalid-french-0002', $aberdeen['sitemap_fragment']);

        $zangi = $pdo->query("SELECT sitemap_fragment, robots FROM registry WHERE route_path = '/mot/zangi'")->fetch();
        Assert::same('invalid-french-0002', $zangi['sitemap_fragment']);
        Assert::same('index,follow', $zangi['robots']);

        // Plafond de fragment jamais depasse (verifie explicitement, pas seulement suppose).
        $fragmentCounts = $pdo->query("SELECT sitemap_fragment, COUNT(*) c FROM registry WHERE family = 'word_french_not_admitted' GROUP BY sitemap_fragment")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($fragmentCounts as $fragment => $count) {
            Assert::true((int) $count <= 2, "fragment {$fragment} depasse le plafond de test : {$count}");
        }
        Assert::same(2, (int) $fragmentCounts['invalid-french-0001']);
        Assert::same(2, (int) $fragmentCounts['invalid-french-0002']);
        unset($pdo);

        // --- Rejeu immediat : delta vide, rien de nouveau insere (idempotence). ---
        [$exitCode2, $stdout2] = $run();
        Assert::same(0, $exitCode2, 'un second passage sans delta doit reussir');
        Assert::true(str_contains($stdout2, 'aucune forme nouvelle'), $stdout2);

        $pdo = new PDO('sqlite:' . $seoPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $familyCountAfterReplay = (int) $pdo->query("SELECT COUNT(*) c FROM registry WHERE family = 'word_french_not_admitted'")->fetch()['c'];
        Assert::same(4, $familyCountAfterReplay, 'rejeu idempotent : aucune ligne supplementaire ni dupliquee');
        unset($pdo);

        // ============================================================================
        // Scenario 2 : controle croise negatif -- une forme non admise du dictionnaire
        // (UNKNOWNWORD) n'apparait dans AUCUN des deux CSV kaikki -- le script doit
        // s'arreter net (ABANDON) plutot que d'appliquer un delta d'origine non prouvee, et
        // ne doit RIEN avoir ecrit (transaction jamais ouverte avant ce controle).
        // ============================================================================
        $buildDictionary([
            ['AALBORG', 0],
            ['UNKNOWNWORD', 0],
        ]);
        $buildRegistry([]);
        $writeKaikkiCsv('names_definitions_final.csv', ['normalized', 'raw', 'definition_fr'], [
            ['AALBORG', 'Aalborg', 'Ville du nord du Danemark.'],
        ]);
        $writeKaikkiCsv('register_definitions_final.csv', ['normalized', 'raw', 'tag', 'definition_fr'], []);

        [$exitCode3, , $stderr3] = $run();
        Assert::true($exitCode3 !== 0, 'une forme hors des CSV kaikki doit faire echouer le script');
        Assert::true(str_contains($stderr3, 'ABANDON'), $stderr3);
        Assert::true(str_contains($stderr3, 'UNKNOWNWORD'), $stderr3);

        $pdo = new PDO('sqlite:' . $seoPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $countAfterAbort = (int) $pdo->query('SELECT COUNT(*) c FROM registry')->fetch()['c'];
        Assert::same(0, $countAfterAbort, 'aucune ligne ne doit avoir ete ecrite avant l\'abandon (controle croise avant toute transaction)');
        unset($pdo);
    } finally {
        $cleanup = static function (string $dir) use (&$cleanup): void {
            if (!is_dir($dir)) {
                return;
            }

            foreach (scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $dir . '/' . $entry;

                if (is_dir($path)) {
                    $cleanup($path);
                } else {
                    unlink($path);
                }
            }

            rmdir($dir);
        };

        $cleanup($tmpDir);
    }
};
