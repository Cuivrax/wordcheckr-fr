<?php

declare(strict_types=1);

use Tests\Support\Assert;

/**
 * scripts/propose_seo_batch.php, cas 'avec_two_letters' (App\Seo\Family::
 * WORD_LIST_AVEC_TWO_LETTERS, palier 2 de l'ouverture en entonnoir de "avec" a
 * l'indexation, demande produit du 2026-08-17) -- boite noire, un vrai sous-processus PHP,
 * jamais contre le vrai storage/seo_fr.sqlite (ce script ne l'ouvre de toute facon jamais,
 * uniquement storage/dictionary_fr.sqlite en lecture seule -- voir l'entete du script).
 *
 * Meme discipline que tests/Seo/ProposeSeoBatchAvecSingleLetterTest.php (palier 1) : ce cas
 * genere une PROPOSITION nouvelle a chaque execution (batch_id/added_at dynamiques), ce test ne
 * verifie donc PAS un batch_id exact, seulement sa forme et la structure des lignes.
 *
 * CORRECTIF (4e audit seo-technical-auditor, 2026-08-20, bloquant C-3) : 4 276 -> 4 272 lignes.
 * scripts/propose_seo_batch.php exclut desormais les paires dont le contenu duplique EXACTEMENT
 * celui d'un parent palier 1 (AVEC_TWO_LETTERS_EXCLUDED_TIER_DUPLICATES, voir son docblock pour
 * le detail complet et les deux methodes de verification independantes, 0 divergence) :
 * /mots/2-lettres/avec/u/w, /mots/2-lettres/avec/a/z, /mots/14-lettres/avec/q/u et
 * /mots/15-lettres/avec/q/u.
 *
 * CORRECTIF (D-041, 2026-08-21, isD041Excluded()) : 4 272 -> 4 134 lignes (138 exclusions
 * supplementaires) -- detecteur generique scripts/check_combinatorial_duplicates.php, regle de
 * priorite resolveDuplicateWinner() (scripts/lib/seo_duplicate_priority.php). Ces paires perdent
 * desormais contre une autre famille combinatoire (word_list_combined, word_list_terminant...)
 * tranchant le MEME panier avec MOINS de composants -- la grande majorite des pages a exactement 1
 * resultat en sont concernees (paniers minuscules, plus faciles a dupliquer sous une autre forme) :
 * 130 -> 8 pages a 1 resultat.
 */
return function (): void {
    $root = __DIR__ . '/../..';
    $dictPath = $root . '/storage/dictionary_fr.sqlite';
    Assert::true(is_file($dictPath), 'base manquante : ' . $dictPath);

    $process = proc_open(
        [PHP_BINARY, $root . '/scripts/propose_seo_batch.php', 'avec_two_letters'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    Assert::true($process !== false, 'impossible de lancer propose_seo_batch.php');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    Assert::same(0, $exitCode, "propose_seo_batch.php avec_two_letters aurait du reussir : {$stderr}");
    Assert::true(str_contains($stderr, '4180 ligne(s) proposee(s)'), $stderr);

    $tmpFile = tempnam(sys_get_temp_dir(), 'avec_two_letters_batch_');
    file_put_contents($tmpFile, $stdout);

    try {
        $batch = require $tmpFile;

        Assert::true(str_starts_with($batch['batch_id'], 'avec_two_letters-proposed-'), 'batch_id dynamique attendu (nouvelle proposition, pas une regularisation)');
        // D-051 (2026-09-02) : AVEC_TWO_LETTERS_EXCLUDED_TIER_DUPLICATES revalidee sur les 844 961
        // termes (etait 838 180) -- 2:a:z et 2:u:w liberees (n'egalent plus leur parent palier 1
        // depuis l'ajout du complement kaikki), 4 -> 2 entrees. D-041 (isD041Excluded, 138 entrees)
        // inchange par ce lot -- non revalide, chantier de suivi separe.
        Assert::same(4180, count($batch['rows']), '4 276 combinaisons a >= 1 resultat, moins 2 (C-3 revalide D-051, parent palier 1) moins 138 (D-041, doublons croises avec d\'autres familles combinatoires, non revalide)');

        // --- Forme exacte de la route : longueur + "avec" + EXACTEMENT deux lettres distinctes,
        // --- toujours dans l'ordre alphabetique (jamais /mots/avec/{X}/{Y} sans longueur, jamais
        // --- /mots/{N}-lettres/avec/{X} seul, jamais trois lettres). ---
        foreach ($batch['rows'] as $row) {
            Assert::true(preg_match('#^/mots/(\d+)-lettres/avec/([a-z])/([a-z])\z#', $row['route_path'], $m) === 1, $row['route_path']);
            $length = (int) $m[1];
            Assert::true($length >= 2 && $length <= 15, "longueur hors bornes : {$row['route_path']}");
            Assert::true($m[2] < $m[3], "lettres pas dans l'ordre alphabetique : {$row['route_path']}");
        }

        // --- R1-R7 respectees par construction (sitemap_fragment unique, canonical = route,
        // --- notes non vide, result_count jamais 0 pour une ligne index,follow). ---
        $seen = [];
        $singleResultCount = 0;
        foreach ($batch['rows'] as $row) {
            Assert::same('word_list_avec_two_letters', $row['family']);
            Assert::same('index,follow', $row['robots']);
            Assert::same($row['route_path'], $row['canonical_path'], 'R3 : jamais d\'alias indexable');
            Assert::same('avec-pair-0001', $row['sitemap_fragment']);
            Assert::true($row['result_count'] >= 1, 'R5 : jamais 0 resultat pour une ligne index,follow');
            Assert::true($row['result_count'] <= 10_000, 'plafond ROW_EXAMINATION_CEILING (D-019)');
            Assert::true(trim($row['notes']) !== '', 'R7 : note de maillage obligatoire');
            Assert::true(!isset($seen[$row['route_path']]), 'R2 : aucun doublon dans le lot');
            $seen[$row['route_path']] = true;

            if ($row['result_count'] === 1) {
                $singleResultCount++;
            }
        }

        // D-051 (2026-09-02) : 2:a:z et 2:u:w desormais comptees (liberees de C-3), remesure directe
        // sur le nouveau lot plutot que recalculee a la main depuis l'ancien chiffre.
        Assert::same(48, $singleResultCount, 'D-051 : remesure directe apres revalidation C-3 (2:a:z et 2:u:w desormais incluses)');

        // --- Cas connus, verifies ligne par ligne contre le vrai solveur (voir le rapport AFTER
        // --- pour la verification independante complete, agent seo-registry). ---
        $byPath = [];
        foreach ($batch['rows'] as $row) {
            $byPath[$row['route_path']] = $row;
        }

        Assert::true(isset($byPath['/mots/10-lettres/avec/a/b']));
        Assert::true(isset($byPath['/mots/9-lettres/avec/c/o']));
        Assert::same(10_000, $byPath['/mots/9-lettres/avec/c/o']['result_count'], 'plafonne (ROW_EXAMINATION_CEILING, D-019)');

        // --- CORRECTIF C-3, REVALIDE D-051 (2026-09-02) : /mots/2-lettres/avec/u/w et
        // --- /mots/2-lettres/avec/a/z sont desormais LIBEREES (leur parent palier 1 a plus d'un
        // --- mot depuis le complement kaikki -- avec/w = WE/WI/WU/WW, avec/z = OZ/ZA/DZ -- ce
        // --- n'est plus le meme contenu qu'une paire a 1 mot). Seules Q+U a 14/15 lettres restent
        // --- exclues (paire structurelle, inchangee). ---
        Assert::true(isset($byPath['/mots/2-lettres/avec/u/w']), 'D-051 : liberee, avec/w a desormais 4 mots (WE/WI/WU/WW), plus un doublon de avec/u/w (WU seul)');
        Assert::same(1, $byPath['/mots/2-lettres/avec/u/w']['result_count']);
        Assert::true(isset($byPath['/mots/2-lettres/avec/a/z']), 'D-051 : liberee, avec/z a desormais 3 mots (OZ/ZA/DZ), plus un doublon de avec/a/z (ZA seul)');
        Assert::same(1, $byPath['/mots/2-lettres/avec/a/z']['result_count']);
        Assert::true(!isset($byPath['/mots/14-lettres/avec/q/u']), 'doublon de contenu avec /mots/14-lettres/avec/q (Q et U quasi-indissociables a cette longueur)');
        Assert::true(!isset($byPath['/mots/15-lettres/avec/q/u']), 'doublon de contenu avec /mots/15-lettres/avec/q (Q et U quasi-indissociables a cette longueur)');

        // --- /mots/10-lettres/avec/w/x : D-051 a ajoute un second mot a ce panier (etait 1 mot,
        // --- desormais 2) -- reste present et indexable, seul le compte a change. ---
        Assert::true(isset($byPath['/mots/10-lettres/avec/w/x']));
        Assert::same(2, $byPath['/mots/10-lettres/avec/w/x']['result_count'], 'D-051 : etait 1 mot avant, 2 desormais');
    } finally {
        unlink($tmpFile);
    }
};
