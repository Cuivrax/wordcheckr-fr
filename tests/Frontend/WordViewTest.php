<?php

declare(strict_types=1);

use App\Search\Conjugation;
use App\Search\TermPage;
use App\Search\TermRelations;
use App\Search\WordSenses;
use Tests\Support\Assert;

/**
 * Rend app/View/word.php avec des fiches synthetiques (les trois statuts, et les
 * bornes de longueur 2 et 15, D-010) sans serveur HTTP ni base de donnees --
 * verifie des invariants structurels sur le HTML produit :
 * - la somme des valeurs de tuiles egale toujours le score affiche (docs/04) ;
 * - une tuile par lettre, dans les deux extremes de longueur ;
 * - aucune mention de source de donnees (D-015 : ni Kartmaan, ni hbenbel, ni ODS
 *   publie par un tiers nomme, ni URL de depot) ;
 * - le formulaire de verification reste un GET natif vers /verifier (fonctionne
 *   sans JavaScript) ;
 * - Phase 4 (relations) : $relations = null (francais non admis / inconnu) ne rend
 *   AUCUNE section relations ; $relations non null rend uniquement les categories
 *   non vides, surligne <mark> la partie conservee/modifiee la ou attendu, groupe
 *   anagramsPlusOne par lettre ajoutee, et n'affiche "Voir les N mots ->" que
 *   lorsque le total depasse les elements deja affiches.
 * - D-018 (nature grammaticale, genre, conjugaison) : pas de carte pos/genre (repli
 *   .sense-card "Nature Grammaticale" dans .direct) si $page->pos est null, pas de
 *   section conjugaison si $conjugation->asLemma ET $conjugation->asForm sont vides.
 *   Le repli pos/genre reutilise exactement le markup .sense-card/.sense-meta/.sense-
 *   label/.sense-pos d'une vraie carte de sens (retour utilisateur : typo identique,
 *   pas seulement similaire -- app/View/word.php et public/assets/css/site.css). Les
 *   fixtures POSER/POSERA/TABLE/ETRE reprennent
 *   des valeurs reelement observees sur storage/dictionary_fr.sqlite (verifie
 *   manuellement pendant l'implementation) plutot que des valeurs inventees.
 *   $relations est deliberement laisse a null sur ces fixtures cibles D-018 meme
 *   quand status = admitted : seul le rendu pos/genre/conjugaison est sous test ici,
 *   pas l'invariant routeur "relations non nul ssi admis" (couvert ailleurs, hors
 *   perimetre de ce fichier de vue).
 */
return function (): void {
    require __DIR__ . '/../../app/bootstrap.php';

    $render = static function (TermPage $page, ?TermRelations $relations, Conjugation $conjugation, WordSenses $senses): string {
        $seo = \App\Seo\SeoMeta::noindex('https://exemple.fr/mot/' . $page->slug);

        ob_start();
        (static function (TermPage $page, ?TermRelations $relations, Conjugation $conjugation, WordSenses $senses, \App\Seo\SeoMeta $seo): void {
            require __DIR__ . '/../../app/View/word.php';
        })($page, $relations, $conjugation, $senses, $seo);

        return (string) ob_get_clean();
    };

    // Aucun sens genere (D-0XX, pilote partiel) : reutilise pour toutes les fixtures --
    // ce fichier couvre D-018 (pos/genre/conjugaison), pas le rendu des cartes de
    // definition (couvert par un test dedie a app/Search/SenseLookup).
    $noSenses = new WordSenses(senses: [], queryCount: 0);

    // Conjugaison vide (mot ni verbe ni forme conjuguee -- l'immense majorite des mots) :
    // reutilisee pour toutes les fixtures qui ne portent pas sur D-018 specifiquement.
    $noConjugation = new Conjugation(asLemma: [], asForm: [], queryCount: 0);

    $tiles = static function (string $normalized, array $tileScores): array {
        $letters = [];
        foreach (str_split($normalized) as $letter) {
            $letters[] = ['letter' => $letter, 'value' => $tileScores[$letter]];
        }

        return $letters;
    };

    /** Item de relation synthetique minimal (contrat TermRelations). */
    $item = static function (string $normalized, array $extra = []): array {
        return array_merge([
            'normalized' => $normalized,
            'slug' => strtolower($normalized),
            'score' => 1,
            'length' => strlen($normalized),
            'isOds8' => true,
            'isOds9' => true,
        ], $extra);
    };

    $tileScores = require __DIR__ . '/../../config/sites/fr.php';
    $tileScores = $tileScores['tile_scores'];

    // -------------------------------------------------------------------
    // AA -- deux lettres (borne basse, D-010). Plusieurs categories structurellement
    // vides (anagrams, removeOneLetter, substrings, anagramsMinusOne, containingWords)
    // -- aucune section correspondante ne doit apparaitre dans le HTML.
    // -------------------------------------------------------------------
    $aaPage = new TermPage(
        normalized: 'AA',
        slug: 'aa',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: 2,
        length: 2,
        isOds8: true,
        isOds9: true,
        letters: $tiles('AA', $tileScores),
        previousWord: null,
        nextWord: 'AABAM',
    );
    $aaRelations = new TermRelations(
        anagrams: [],
        changeOneLetter: [$item('AB', ['position' => 2, 'newLetter' => 'B'])],
        removeOneLetter: [],
        insertOneLetter: [$item('ACA')],
        substrings: [],
        rightExtensions: [$item('AAH'), $item('AAS')],
        rightExtensionsTotal: 5,
        rightExtensionsTruncated: false,
        leftExtensions: [$item('BAA')],
        leftExtensionsTotal: 1,
        leftExtensionsTruncated: false,
        containingWords: [],
        containingWordsTotal: 0,
        containingWordsTruncated: false,
        anagramsPlusOne: [
            $item('AAB', ['addedLetter' => 'B']),
            $item('ABA', ['addedLetter' => 'B']),
            $item('CAA', ['addedLetter' => 'C']),
        ],
        anagramsMinusOne: [],
        relatedSearches: [
            ['type' => 'length', 'url' => '/mots/2-lettres'],
            ['type' => 'startsWith', 'url' => '/mots/commencant/a'],
            ['type' => 'play', 'url' => '/jouer/aa'],
        ],
        queryCount: 5,
    );

    $htmlAa = $render($aaPage, $aaRelations, $noConjugation, $noSenses);

    Assert::true(str_contains($htmlAa, 'Jouer Autour De AA'), 'AA : titre de section relations attendu');

    // Categories structurellement vides : aucune trace de leur titre exact.
    Assert::true(!str_contains($htmlAa, '<span>Anagrammes</span>'), 'AA : anagrammes exactes structurellement vide, aucune section');
    Assert::true(!str_contains($htmlAa, '<span>Retirer Une Lettre</span>'), 'AA : retirer une lettre structurellement vide, aucune section');
    Assert::true(!str_contains($htmlAa, '<span>Sous-Mots</span>'), 'AA : sous-mots structurellement vide, aucune section');
    Assert::true(!str_contains($htmlAa, '<span>Anagrammes Avec Une Lettre En Moins</span>'), 'AA : anagrammes -1 lettre structurellement vide, aucune section');
    Assert::true(!str_contains($htmlAa, 'AA Dans Un Mot Plus Long'), 'AA : mot contenu structurellement vide, aucune section');

    // changeOneLetter : position/newLetter fournis par le backend, surlignage attendu.
    Assert::true(str_contains($htmlAa, 'A<mark>B</mark>'), 'AA : changeOneLetter doit surligner la lettre en position 2 (AB)');

    // insertOneLetter : surlignage calcule par comparaison de chaines (ACA = A + C inseree).
    Assert::true(str_contains($htmlAa, 'A<mark>C</mark>A'), 'AA : insertOneLetter doit surligner la lettre inseree (ACA)');

    // rightExtensions : mot pivot surligne en prefixe, lien "Voir les N mots" car total (5) > items affiches (2).
    Assert::true(str_contains($htmlAa, '<mark>AA</mark>H'), 'AA : rightExtensions doit surligner le prefixe (AAH)');
    Assert::true(str_contains($htmlAa, '<mark>AA</mark>S'), 'AA : rightExtensions doit surligner le prefixe (AAS)');
    Assert::true(str_contains($htmlAa, 'href="/mots/commencant/aa"'), 'AA : lien "Voir les N mots" attendu pour rightExtensions (total > affiches)');
    Assert::true(str_contains($htmlAa, 'Voir les 5 mots'), 'AA : libelle du lien rightExtensions avec le total exact');

    // leftExtensions : mot pivot surligne en suffixe, PAS de lien "Voir les N mots" (total == affiches).
    Assert::true(str_contains($htmlAa, 'B<mark>AA</mark>'), 'AA : leftExtensions doit surligner le suffixe (BAA)');
    Assert::true(!str_contains($htmlAa, 'href="/mots/terminant/aa"'), 'AA : aucun lien "Voir les N mots" pour leftExtensions (total == affiches)');
    Assert::true(str_contains($htmlAa, '1 mot<'), 'AA : compte au singulier pour leftExtensions (1 mot)');

    // anagramsPlusOne : regroupement par lettre ajoutee, pas de surlignage.
    Assert::true(str_contains($htmlAa, '<span class="plus">+B</span>'), 'AA : groupe +B attendu pour anagramsPlusOne');
    Assert::true(str_contains($htmlAa, '<span class="plus">+C</span>'), 'AA : groupe +C attendu pour anagramsPlusOne');
    Assert::true(str_contains($htmlAa, '>AAB<') && str_contains($htmlAa, '>ABA<') && str_contains($htmlAa, '>CAA<'), 'AA : les trois mots anagramsPlusOne doivent apparaitre en liens');

    // Recherches liees.
    Assert::true(str_contains($htmlAa, 'Recherches Liées'), 'AA : section recherches liees attendue');
    Assert::true(str_contains($htmlAa, '>Mots De 2 Lettres<'), 'AA : libelle "length" attendu');
    Assert::true(str_contains($htmlAa, '>Commençant Par A<'), 'AA : libelle "startsWith" attendu');
    Assert::true(str_contains($htmlAa, '>Jouer Avec AA<'), 'AA : libelle "play" attendu');

    // -------------------------------------------------------------------
    // ABANDONNATRICES -- quinze lettres (borne haute, D-010). insertOneLetter,
    // anagramsPlusOne, rightExtensions, leftExtensions, containingWords sont
    // structurellement vides (aucun mot de 16 lettres ne peut jamais exister en base).
    // -------------------------------------------------------------------
    $longPage = new TermPage(
        normalized: 'ABANDONNATRICES',
        slug: 'abandonnatrices',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: 20,
        length: 15,
        isOds8: true,
        isOds9: true,
        letters: $tiles('ABANDONNATRICES', $tileScores),
        previousWord: 'ABANDONNATRICE',
        nextWord: 'ABANDONNE',
    );
    $longRelations = new TermRelations(
        anagrams: [],
        changeOneLetter: [$item('ABANDONNATRICEX', ['position' => 15, 'newLetter' => 'X'])],
        removeOneLetter: [$item('ABANDONNATRICE')],
        insertOneLetter: [],
        substrings: [$item('BANDON')],
        rightExtensions: [],
        rightExtensionsTotal: 0,
        rightExtensionsTruncated: false,
        leftExtensions: [],
        leftExtensionsTotal: 0,
        leftExtensionsTruncated: false,
        containingWords: [],
        containingWordsTotal: 0,
        containingWordsTruncated: false,
        anagramsPlusOne: [],
        anagramsMinusOne: [$item('ABANDONNATRICE')],
        relatedSearches: [
            ['type' => 'length', 'url' => '/mots/15-lettres'],
        ],
        queryCount: 5,
    );

    $htmlLong = $render($longPage, $longRelations, $noConjugation, $noSenses);

    Assert::true(str_contains($htmlLong, 'Jouer Autour De ABANDONNATRICES'), 'ABANDONNATRICES : titre de section relations attendu');
    Assert::true(!str_contains($htmlLong, '<span>Insérer Une Lettre</span>'), 'ABANDONNATRICES : inserer une lettre structurellement vide (D-010)');
    Assert::true(!str_contains($htmlLong, '<span>Anagrammes Avec Une Lettre En Plus</span>'), 'ABANDONNATRICES : anagrammes +1 lettre structurellement vide (D-010)');
    Assert::true(!str_contains($htmlLong, '<span>Rallonges À Droite</span>'), 'ABANDONNATRICES : rallonges a droite structurellement vide (D-010)');
    Assert::true(!str_contains($htmlLong, '<span>Rallonges À Gauche</span>'), 'ABANDONNATRICES : rallonges a gauche structurellement vide (D-010)');
    Assert::true(!str_contains($htmlLong, 'ABANDONNATRICES Dans Un Mot Plus Long'), 'ABANDONNATRICES : mot contenu structurellement vide (D-010)');
    Assert::true(str_contains($htmlLong, 'ABANDONNATRICE<mark>X</mark>'), 'ABANDONNATRICES : changeOneLetter doit surligner la derniere position');

    // -------------------------------------------------------------------
    // Francais non admis / inconnu -- $relations = null : aucune section relations.
    // -------------------------------------------------------------------
    $notAdmittedPage = new TermPage(
        normalized: 'GHOSTER',
        slug: 'ghoster',
        found: true,
        status: TermPage::STATUS_FRENCH_NOT_ADMITTED,
        score: 11,
        length: 7,
        isOds8: false,
        isOds9: false,
        letters: $tiles('GHOSTER', $tileScores),
        previousWord: 'GHOSTENT',
        nextWord: 'GHOSTERA',
        // pos renseigne malgre le statut "francais non admis" : D-018 s'applique
        // independamment du statut Scrabble (voir docs/DECISIONS.md D-018) -- couvre
        // explicitement "un mot francais non admis avec pos renseigne".
        pos: 'V',
    );
    $htmlNotAdmitted = $render($notAdmittedPage, null, $noConjugation, $noSenses);
    Assert::true(!str_contains($htmlNotAdmitted, 'class="relations"'), 'GHOSTER : francais non admis, aucune section relations');
    Assert::true(!str_contains($htmlNotAdmitted, 'Recherches Liées'), 'GHOSTER : francais non admis, aucune recherche liee');
    Assert::true(
        str_contains($htmlNotAdmitted, '<span class="sense-label">Nature Grammaticale</span> <span class="sense-pos">Verbe</span>'),
        'GHOSTER : pos renseigne malgre le statut non admis, carte nature grammaticale attendue (meme markup que .sense-card)',
    );
    Assert::true(!str_contains($htmlNotAdmitted, 'class="conjugation"'), 'GHOSTER : aucune donnee de conjugaison, aucune section');
    // D-054 : nonAdmittedCategory non renseigne sur cette fixture (repli sur la phrase
    // generique) -- couvre le cas majoritaire (435 120 formes anterieures a D-051), aucune
    // regression attendue.
    Assert::true(
        str_contains($htmlNotAdmitted, 'GHOSTER existe en français, mais ce mot n’est pas admis dans le dictionnaire officiel du Scrabble.'),
        'GHOSTER : nonAdmittedCategory NULL, phrase generique "Reponse Directe" inchangee (D-054, pas de regression)',
    );

    $unknownPage = new TermPage(
        normalized: 'ZZZQQQXXX',
        slug: 'zzzqqqxxx',
        found: false,
        status: TermPage::STATUS_UNKNOWN,
        score: 84,
        length: 9,
        isOds8: false,
        isOds9: false,
        letters: $tiles('ZZZQQQXXX', $tileScores),
        previousWord: 'ZYZZYVAS',
        nextWord: null,
    );
    $htmlUnknown = $render($unknownPage, null, $noConjugation, $noSenses);
    Assert::true(!str_contains($htmlUnknown, 'class="relations"'), 'ZZZQQQXXX : inconnu, aucune section relations');
    Assert::true(!str_contains($htmlUnknown, 'Recherches Liées'), 'ZZZQQQXXX : inconnu, aucune recherche liee');
    Assert::true(!str_contains($htmlUnknown, 'Nature Grammaticale'), 'ZZZQQQXXX : pos absent (terme inconnu), aucune carte nature grammaticale');
    Assert::true(!str_contains($htmlUnknown, 'class="conjugation"'), 'ZZZQQQXXX : pos absent, aucune section conjugaison');

    // -------------------------------------------------------------------
    // D-018 -- nature grammaticale, genre, conjugaison. Valeurs reprises telles
    // qu'observees sur storage/dictionary_fr.sqlite (verifie manuellement pendant
    // l'implementation, App\Search\TermLookup/ConjugationLookup), pas inventees.
    // -------------------------------------------------------------------
    $scoreFor = static function (string $word) use ($tileScores): int {
        return array_sum(array_map(static fn (string $letter): int => $tileScores[$letter], str_split($word)));
    };

    // POSER (lemme) : selection representative present/futur/imparfait/participes,
    // jusqu'a 20 formes -- ici un sous-ensemble suffisant pour couvrir les cinq temps
    // et le regroupement par forme homographe (POSE = 1s ET 3s au present).
    $poserPage = new TermPage(
        normalized: 'POSER',
        slug: 'poser',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('POSER'),
        length: 5,
        isOds8: true,
        isOds9: true,
        letters: $tiles('POSER', $tileScores),
        previousWord: 'POSEUR',
        nextWord: 'POSERA',
        pos: 'V',
    );
    $poserConjugation = new Conjugation(
        asLemma: [
            ['form' => 'POSE', 'slug' => 'pose', 'tense' => 'present', 'person' => '1s'],
            ['form' => 'POSE', 'slug' => 'pose', 'tense' => 'present', 'person' => '3s'],
            ['form' => 'POSES', 'slug' => 'poses', 'tense' => 'present', 'person' => '2s'],
            ['form' => 'POSERA', 'slug' => 'posera', 'tense' => 'future', 'person' => '3s'],
            ['form' => 'POSAIT', 'slug' => 'posait', 'tense' => 'imperfect', 'person' => '3s'],
            ['form' => 'POSANT', 'slug' => 'posant', 'tense' => 'participle_present', 'person' => null],
            ['form' => 'POSE', 'slug' => 'pose', 'tense' => 'participle_past', 'person' => null],
        ],
        asForm: [],
        queryCount: 1,
    );
    $htmlPoser = $render($poserPage, null, $poserConjugation, $noSenses);
    Assert::true(
        str_contains($htmlPoser, '<span class="sense-label">Nature Grammaticale</span> <span class="sense-pos">Verbe</span>'),
        'POSER : carte nature grammaticale "Verbe" attendue',
    );
    Assert::true(str_contains($htmlPoser, '<h2>Se Conjugue</h2>'), 'POSER : titre "Se Conjugue" attendu (asLemma non vide)');
    Assert::true(!str_contains($htmlPoser, '<h2>Conjugaison</h2>'), 'POSER : pas le titre generique "Conjugaison" (asLemma non vide)');
    foreach (['Présent', 'Futur', 'Imparfait', 'Participe présent', 'Participe passé'] as $tenseLabel) {
        Assert::true(str_contains($htmlPoser, '<span class="plus">' . $tenseLabel . '</span>'), 'POSER : groupe de temps "' . $tenseLabel . '" attendu');
    }
    // POSE (1s et 3s au present) : une seule occurrence dans le flux du groupe Present,
    // les deux personnes fusionnees au survol plutot que le mot duplique visuellement.
    Assert::true(
        str_contains($htmlPoser, '<a href="/mot/pose" title="1re pers. sing. / 3e pers. sing.">POSE</a>'),
        'POSER : POSE (present 1s+3s) doit etre fusionne en un seul lien avec les deux personnes au survol',
    );
    Assert::true(
        str_contains($htmlPoser, '<a href="/mot/poses" title="2e pers. sing.">POSES</a>'),
        'POSER : POSES (present 2s) attendu avec sa personne au survol',
    );
    Assert::true(
        str_contains($htmlPoser, '<a href="/mot/posant">POSANT</a>'),
        'POSER : POSANT (participe present, aucune personne) ne doit porter aucun attribut title',
    );

    // POSERA (forme) : pointe vers POSER, futur 3s -- exemple nomme explicitement (CLAUDE.md).
    $poseraPage = new TermPage(
        normalized: 'POSERA',
        slug: 'posera',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('POSERA'),
        length: 6,
        isOds8: true,
        isOds9: true,
        letters: $tiles('POSERA', $tileScores),
        previousWord: 'POSER',
        nextWord: 'POSERAI',
        pos: 'V',
    );
    $poseraConjugation = new Conjugation(
        asLemma: [],
        asForm: [['lemma' => 'POSER', 'slug' => 'poser', 'tense' => 'future', 'person' => '3s']],
        queryCount: 1,
    );
    $htmlPosera = $render($poseraPage, null, $poseraConjugation, $noSenses);
    // D-0XX : la section "Conjugaison" separee n'existe plus pour une simple forme conjuguee
    // (asLemma vide) -- l'information vit desormais UNIQUEMENT dans la carte "Definition"
    // (retour utilisateur : la meme info a deux endroits, formulee differemment, lisait comme
    // une incoherence, pas une variation voulue).
    Assert::true(!str_contains($htmlPosera, '<h2>Conjugaison</h2>'), 'POSERA : plus de section Conjugaison separee (D-0XX, info dans la carte Definition)');
    Assert::true(!str_contains($htmlPosera, '<h2>Se Conjugue</h2>'), 'POSERA : pas "Se Conjugue", POSERA n\'est pas un infinitif');
    Assert::true(!str_contains($htmlPosera, 'Nature Grammaticale'), 'POSERA : pas de carte "Nature Grammaticale", supplantee par la carte Definition (forme conjuguee)');
    // D-0XX (rotation de gabarits) : POSERA -> crc32("POSERA") % 4 = 3 (gabarit E), stable et
    // deterministe -- recalcule ici plutot que suppose, voir app/View/word.php pour le detail
    // des 4 gabarits. Synthetisee directement dans la carte "Definition" ($noSenses ici --
    // aucune fiche word_senses reelle a remplacer, meme chemin que ABADAIENT).
    Assert::true(
        str_contains($htmlPosera, '<span class="sense-label">Définition</span> <span class="sense-pos">verbe</span>'),
        'POSERA : carte Definition avec etiquette "verbe" attendue',
    );
    Assert::true(
        str_contains($htmlPosera, 'Conjugaison à la 3e pers. sing. du futur du verbe <a href="/mot/poser">POSER</a>.'),
        'POSERA : phrase courte attendue, temps/personne traduits en francais (gabarit E, crc32("POSERA") % 4 = 3)',
    );

    // TABLE : homographe nom/verbe reel (D-018). asLemma vide (TABLE n'est pas un
    // infinitif connu), asForm non vide (TABLE EST une forme conjuguee de TABLER).
    $tablePage = new TermPage(
        normalized: 'TABLE',
        slug: 'table',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('TABLE'),
        length: 5,
        isOds8: true,
        isOds9: true,
        letters: $tiles('TABLE', $tileScores),
        previousWord: 'TABLAS',
        nextWord: 'TABLEAU',
        pos: 'N',
        posSecondary: 'V',
        gender: 'f',
    );
    $tableConjugation = new Conjugation(
        asLemma: [],
        asForm: [
            ['lemma' => 'TABLER', 'slug' => 'tabler', 'tense' => 'participle_past', 'person' => null],
            ['lemma' => 'TABLER', 'slug' => 'tabler', 'tense' => 'present', 'person' => '1s'],
            ['lemma' => 'TABLER', 'slug' => 'tabler', 'tense' => 'present', 'person' => '3s'],
        ],
        queryCount: 1,
    );
    $htmlTable = $render($tablePage, null, $tableConjugation, $noSenses);
    // D-0XX : $posLine ("Nature Grammaticale") est desormais supprime des qu'une forme
    // conjuguee existe (asForm non vide) -- une carte Definition va de toute facon etre
    // affichee, $posLine y ferait doublon (retour utilisateur, meme raisonnement que
    // POSERA/ABADAIENT). Remplace par UN SEUL representant choisi par ordre canonique
    // temps/personne parmi les 3 entrees asForm (participe_passe rang 4, present/1s rang
    // [0,0], present/3s rang [0,2]) -- present/1s gagne, jamais tous les 3 rendus separement
    // (evite la repetition "4 fois la meme chose" signalee sur AMOCHE en production reelle).
    Assert::true(!str_contains($htmlTable, 'Nature Grammaticale'), 'TABLE : plus de carte "Nature Grammaticale", supplantee par la carte Definition');
    Assert::true(
        str_contains($htmlTable, '<span class="sense-label">Définition</span> <span class="sense-pos">verbe</span>'),
        'TABLE : carte Definition avec etiquette "verbe" attendue',
    );
    // crc32("TABLE") % 4 = 2 (gabarit D), stable.
    Assert::true(
        str_contains($htmlTable, "Cette forme vient du verbe <a href=\"/mot/tabler\">TABLER</a>, conjugué au présent à la 1re pers. sing."),
        'TABLE : phrase du representant (present 1s, gagne sur present 3s et participe passe par ordre canonique) attendue (gabarit D, crc32("TABLE") % 4 = 2)',
    );
    Assert::true(!str_contains($htmlTable, 'participe passé'), 'TABLE : le participe passe ne doit PAS etre rendu, un seul representant par mot');
    Assert::true(!str_contains($htmlTable, '3e pers. sing.'), 'TABLE : present/3s ne doit PAS etre rendu, un seul representant par mot');
    Assert::true(!str_contains($htmlTable, '<h2>Conjugaison</h2>') && !str_contains($htmlTable, '<h2>Se Conjugue</h2>'), 'TABLE : pas de section Conjugaison separee (asLemma vide)');

    // CHAT : nom simple, aucune conjugaison -- section entiere absente.
    $chatPage = new TermPage(
        normalized: 'CHAT',
        slug: 'chat',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('CHAT'),
        length: 4,
        isOds8: true,
        isOds9: true,
        letters: $tiles('CHAT', $tileScores),
        previousWord: 'CHASSIS',
        nextWord: 'CHATOIE',
        pos: 'N',
        gender: 'm',
    );
    $htmlChat = $render($chatPage, null, $noConjugation, $noSenses);
    Assert::true(
        str_contains($htmlChat, '<span class="sense-label">Nature Grammaticale</span> <span class="sense-pos">Nom masculin</span>'),
        'CHAT : carte nature grammaticale "Nom masculin" attendue',
    );
    Assert::true(!str_contains($htmlChat, 'class="conjugation"'), 'CHAT : aucune donnee de conjugaison, aucune section');

    // ETRE : verbe suppletif exclu de verb_forms (non fiable, D-018) -- asLemma vide
    // malgre pos=V. Homographe reel avec le nom "un etre" (posSecondary=N, gender=m).
    $etrePage = new TermPage(
        normalized: 'ETRE',
        slug: 'etre',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('ETRE'),
        length: 4,
        isOds8: true,
        isOds9: true,
        letters: $tiles('ETRE', $tileScores),
        previousWord: 'ETRAVE',
        nextWord: 'ETREINDRE',
        pos: 'V',
        posSecondary: 'N',
        gender: 'm',
    );
    $htmlEtre = $render($etrePage, null, $noConjugation, $noSenses);
    Assert::true(
        str_contains($htmlEtre, '<span class="sense-label">Nature Grammaticale</span> <span class="sense-pos">Verbe, aussi nom masculin</span>'),
        'ETRE : carte nature grammaticale "Verbe, aussi nom masculin" attendue',
    );
    Assert::true(!str_contains($htmlEtre, 'class="conjugation"'), 'ETRE : verbe supplétif exclu, aucune section conjugaison malgre pos=V');

    // Francais non admis + conjugaison (ex. reel : ABADAIENT -> ABADER, pos absent) :
    // la section conjugaison ne depend pas du statut Scrabble ni de la presence de pos.
    $formOnlyNotAdmittedPage = new TermPage(
        normalized: 'ABADAIENT',
        slug: 'abadaient',
        found: true,
        status: TermPage::STATUS_FRENCH_NOT_ADMITTED,
        score: $scoreFor('ABADAIENT'),
        length: 9,
        isOds8: false,
        isOds9: false,
        letters: $tiles('ABADAIENT', $tileScores),
        previousWord: 'ABADAI',
        nextWord: 'ABADAIS',
    );
    $formOnlyConjugation = new Conjugation(
        asLemma: [],
        asForm: [['lemma' => 'ABADER', 'slug' => 'abader', 'tense' => 'imperfect', 'person' => '3p']],
        queryCount: 1,
    );
    $htmlFormOnlyNotAdmitted = $render($formOnlyNotAdmittedPage, null, $formOnlyConjugation, $noSenses);
    Assert::true(!str_contains($htmlFormOnlyNotAdmitted, 'Nature Grammaticale'), 'ABADAIENT : pos absent, aucune carte nature grammaticale');
    // D-0XX (rotation de gabarits) : crc32("ABADAIENT") % 4 = 2 (gabarit D), stable. Mot
    // francais non admis, aucune fiche word_senses (D-043/D-052 ne couvrent pas les 435 120
    // formes anterieures a D-051) -- carte "Definition" SYNTHETISEE depuis Conjugation
    // (asForm), seule source de cette info, pas de section "Conjugaison" separee.
    Assert::true(
        str_contains($htmlFormOnlyNotAdmitted, '<span class="sense-label">Définition</span> <span class="sense-pos">verbe</span>'),
        'ABADAIENT : carte Definition synthetisee (etiquette "verbe") attendue malgre l\'absence de word_senses',
    );
    Assert::true(
        str_contains($htmlFormOnlyNotAdmitted, "Cette forme vient du verbe <a href=\"/mot/abader\">ABADER</a>, conjugué à l'imparfait à la 3e pers. plur."),
        'ABADAIENT : phrase de la carte Definition attendue malgre le statut francais non admis (gabarit D)',
    );
    // D-054 : ABADAIENT aussi anterieur a D-051 (nonAdmittedCategory NULL) -- confirme le
    // repli generique sur un second cas independant de GHOSTER.
    Assert::true(
        str_contains($htmlFormOnlyNotAdmitted, 'ABADAIENT existe en français, mais ce mot n’est pas admis dans le dictionnaire officiel du Scrabble.'),
        'ABADAIENT : nonAdmittedCategory NULL, phrase generique "Reponse Directe" inchangee (D-054, pas de regression)',
    );

    // -------------------------------------------------------------------
    // D-0XX (repli regex, retour utilisateur sur capture d'ecran reelle -- MANAGERA) : ~65%
    // des cartes "forme conjuguee" (pos=V, source=template) n'ont AUCUNE ligne verb_forms
    // correspondante ($conjugation->asForm reste vide) -- avant ce repli, le lemme stocke en
    // base n'etait jamais transforme en lien ni mis en majuscule. Fixtures MANAGERA/DESENVASEES/
    // CITERENT/SEYAIENT reprennent des valeurs reellement observees sur
    // storage/dictionary_fr.sqlite (verifie manuellement pendant l'implementation), pas
    // inventees -- meme discipline que les fixtures D-018 plus haut dans ce fichier.
    $managera = new TermPage(
        normalized: 'MANAGERA',
        slug: 'managera',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('MANAGERA'),
        length: 8,
        isOds8: true,
        isOds9: true,
        letters: $tiles('MANAGERA', $tileScores),
        previousWord: 'MANAGENT',
        nextWord: 'MANAGERAI',
        pos: 'V',
    );
    $manageraSenses = new WordSenses(
        senses: [['pos' => 'V', 'gender' => null, 'definition' => 'Forme conjuguée du verbe manager (futur, 3e personne du singulier).', 'source' => 'template']],
        queryCount: 1,
    );
    $htmlManagera = $render($managera, null, $noConjugation, $manageraSenses);
    // crc32("MANAGERA") % 4 = 2 (gabarit D), stable -- meme mecanisme de selection que la
    // donnee vivante Conjugation, verifie en direct contre le rendu reel avant d'ecrire cette
    // assertion (jamais recalcule a la main sans confirmation).
    Assert::true(
        str_contains($htmlManagera, "Cette forme vient du verbe <a href=\"/mot/manager\">MANAGER</a>, conjugué au futur à la 3e pers. sing."),
        'MANAGERA : lemme lie et majuscule, rotation appliquee malgre verb_forms vide (repli regex)',
    );
    Assert::true(!str_contains($htmlManagera, '>manager<'), 'MANAGERA : jamais le lemme brut en minuscule dans un lien');

    // Participe (genre/nombre parfois dans le detail source, structure differente des 4
    // gabarits) : lien seul, DETAIL jamais retouche.
    $desenvasees = new TermPage(
        normalized: 'DESENVASEES',
        slug: 'desenvasees',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('DESENVASEES'),
        length: 11,
        isOds8: true,
        isOds9: true,
        letters: $tiles('DESENVASEES', $tileScores),
        previousWord: 'DESENVASEE',
        nextWord: 'DESENVASER',
        pos: 'V',
    );
    $desenvaseesSenses = new WordSenses(
        senses: [['pos' => 'V', 'gender' => null, 'definition' => 'Participe passé féminin pluriel du verbe désenvaser.', 'source' => 'template']],
        queryCount: 1,
    );
    $htmlDesenvasees = $render($desenvasees, null, $noConjugation, $desenvaseesSenses);
    Assert::true(
        str_contains($htmlDesenvasees, 'Participe passé féminin pluriel du verbe <a href="/mot/désenvaser">DÉSENVASER</a>.'),
        'DESENVASEES : participe -- lien seul, detail (genre/nombre) jamais retouche',
    );

    // Aucun detail temps/personne du tout dans la glose source -- lien seul, aucun gabarit
    // variable possible (pas de donnee a faire varier).
    $citerent = new TermPage(
        normalized: 'CITERENT',
        slug: 'citerent',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('CITERENT'),
        length: 8,
        isOds8: true,
        isOds9: true,
        letters: $tiles('CITERENT', $tileScores),
        previousWord: 'CITEREE',
        nextWord: 'CITERIONS',
        pos: 'V',
    );
    $citerentSenses = new WordSenses(
        senses: [['pos' => 'V', 'gender' => null, 'definition' => 'Forme conjuguée du verbe citer.', 'source' => 'template']],
        queryCount: 1,
    );
    $htmlCiterent = $render($citerent, null, $noConjugation, $citerentSenses);
    Assert::true(
        str_contains($htmlCiterent, 'Forme conjuguée du verbe <a href="/mot/citer">CITER</a>.'),
        'CITERENT : aucun detail temps/personne -- lien seul',
    );

    // Glose source deja malformee (fragment tronque -- cas reel "seoir (au sens...", jamais
    // ferme) : garde-fou (tense_phrase extrait contient "verbe") -- laissee TOTALEMENT
    // inchangee plutot que de risquer une reconstruction fautive.
    $seyaient = new TermPage(
        normalized: 'SEYAIENT',
        slug: 'seyaient',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('SEYAIENT'),
        length: 8,
        isOds8: true,
        isOds9: true,
        letters: $tiles('SEYAIENT', $tileScores),
        previousWord: 'SEXY',
        nextWord: 'SEZIG',
        pos: 'V',
    );
    $seyaientDefinition = 'Forme conjuguée du verbe convenir) (imparfait du verbe seoir (au sens, 3e personne du pluriel).';
    $seyaientSenses = new WordSenses(
        senses: [['pos' => 'V', 'gender' => null, 'definition' => $seyaientDefinition, 'source' => 'template']],
        queryCount: 1,
    );
    $htmlSeyaient = $render($seyaient, null, $noConjugation, $seyaientSenses);
    Assert::true(
        str_contains($htmlSeyaient, e($seyaientDefinition)),
        'SEYAIENT : glose source malformee -- laissee totalement inchangee (garde-fou), aucun lien inseree',
    );
    Assert::true(!str_contains($htmlSeyaient, '<a href="/mot/convenir'), 'SEYAIENT : aucune tentative de lien sur une base suspecte');

    // -------------------------------------------------------------------
    // D-0XX (extension pluriel/feminin/masculin, meme repli regex que le verbe ci-dessus,
    // pos=N ou Adj, source=template) : 66 671 cartes "Forme plurielle/feminine/masculine de X."
    // n'avaient jamais de lien ni de rotation avant cette extension. Fixtures MENISCALES/
    // ABRASIVE/AALENIENS/AILS reprennent des valeurs reellement observees en local (verifie
    // manuellement pendant l'implementation), pas inventees -- meme discipline que MANAGERA et
    // consorts plus haut.
    $meniscales = new TermPage(
        normalized: 'MENISCALES',
        slug: 'meniscales',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('MENISCALES'),
        length: 10,
        isOds8: true,
        isOds9: true,
        letters: $tiles('MENISCALES', $tileScores),
        previousWord: 'MENISCALE',
        nextWord: 'MENISCITE',
        pos: 'Adj',
    );
    $meniscalesSenses = new WordSenses(
        senses: [['pos' => 'Adj', 'gender' => null, 'definition' => 'Forme féminine plurielle de méniscal.', 'source' => 'template']],
        queryCount: 1,
    );
    $htmlMeniscales = $render($meniscales, null, $noConjugation, $meniscalesSenses);
    // crc32("MENISCALES") % 4 = 1 (gabarit B), stable -- verifie en direct contre le rendu reel
    // avant d'ecrire cette assertion (jamais recalcule a la main sans confirmation).
    Assert::true(
        str_contains($htmlMeniscales, 'Féminin pluriel de <a href="/mot/méniscal">MÉNISCAL</a>.'),
        'MENISCALES : lemme lie et majuscule, qualificatif convertit en nom ("féminine plurielle" -> "Féminin pluriel"), seule la 1re lettre capitalisee',
    );

    // "de l'adjectif LEMME" -- elision recalculee sur LEMME (pas sur "l'adjectif" comme dans le
    // texte source), contrairement au "d'"/"de " direct qui est repris tel quel.
    $abrasive = new TermPage(
        normalized: 'ABRASIVE',
        slug: 'abrasive',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('ABRASIVE'),
        length: 8,
        isOds8: true,
        isOds9: true,
        letters: $tiles('ABRASIVE', $tileScores),
        previousWord: 'ABRASIONS',
        nextWord: 'ABRASIVES',
        pos: 'Adj',
    );
    $abrasiveSenses = new WordSenses(
        senses: [['pos' => 'Adj', 'gender' => null, 'definition' => "Forme féminine de l'adjectif abrasif.", 'source' => 'template']],
        queryCount: 1,
    );
    $htmlAbrasive = $render($abrasive, null, $noConjugation, $abrasiveSenses);
    // crc32("ABRASIVE") % 4 = 1 (gabarit B).
    Assert::true(
        str_contains($htmlAbrasive, "Féminin d'<a href=\"/mot/abrasif\">ABRASIF</a>."),
        "ABRASIVE : elision recalculee sur ABRASIF (voyelle) -- \"d'\", pas \"de l'adjectif\" ni \"de\"",
    );

    // Deux sens DISTINCTS pour le meme mot (adjectif ET nom propre) -- chacun substitue
    // independamment, jamais qu'un seul pris au hasard (bug trouve et corrige pendant
    // l'implementation : un premier essai avec un simple `break` ne traitait que le premier).
    $aaleniens = new TermPage(
        normalized: 'AALENIENS',
        slug: 'aaleniens',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('AALENIENS'),
        length: 9,
        isOds8: true,
        isOds9: true,
        letters: $tiles('AALENIENS', $tileScores),
        previousWord: 'AALENIENNE',
        nextWord: 'AALENIENNES',
        pos: 'Adj',
    );
    $aaleniensSenses = new WordSenses(
        senses: [
            ['pos' => 'Adj', 'gender' => null, 'definition' => 'Forme masculine plurielle de aalénien.', 'source' => 'template'],
            ['pos' => 'N', 'gender' => null, 'definition' => 'Forme plurielle de aalénien.', 'source' => 'template'],
        ],
        queryCount: 2,
    );
    $htmlAaleniens = $render($aaleniens, null, $noConjugation, $aaleniensSenses);
    // crc32("AALENIENS") % 4 = 2 (gabarit D) -- meme variant sur les deux cartes : la rotation
    // est stable par PAGE, pas par sens.
    Assert::true(
        str_contains($htmlAaleniens, 'Cette forme est le masculin pluriel de <a href="/mot/aalénien">AALÉNIEN</a>.'),
        'AALENIENS : premier sens (adjectif) substitue',
    );
    Assert::true(
        str_contains($htmlAaleniens, 'Cette forme est le pluriel de <a href="/mot/aalénien">AALÉNIEN</a>.'),
        'AALENIENS : second sens (nom propre), DISTINCT du premier, egalement substitue',
    );

    // Detail additionnel apres le lemme (parenthese) -- la regle stricte "\.$" ne trouve pas de
    // point immediatement apres le lemme, aucune correspondance : texte source laisse TOTALEMENT
    // inchange plutot que de risquer une reconstruction fautive (meme prudence que SEYAIENT).
    $ails = new TermPage(
        normalized: 'AILS',
        slug: 'ails',
        found: true,
        status: TermPage::STATUS_ADMITTED,
        score: $scoreFor('AILS'),
        length: 4,
        isOds8: true,
        isOds9: true,
        letters: $tiles('AILS', $tileScores),
        previousWord: 'AIGUS',
        nextWord: 'AIMABLE',
        pos: 'N',
    );
    $ailsDefinition = 'Forme plurielle de ail (utilisé en particulier par les scientifiques).';
    $ailsSenses = new WordSenses(
        senses: [['pos' => 'N', 'gender' => null, 'definition' => $ailsDefinition, 'source' => 'template']],
        queryCount: 1,
    );
    $htmlAils = $render($ails, null, $noConjugation, $ailsSenses);
    Assert::true(
        str_contains($htmlAils, e($ailsDefinition)),
        'AILS : detail apres le lemme -- laisse totalement inchange, aucun lien insere',
    );
    Assert::true(!str_contains($htmlAils, '<a href="/mot/ail"'), 'AILS : aucune tentative de lien sur une glose non conforme');

    // -------------------------------------------------------------------
    // D-054 -- phrase "Reponse Directe" specifique selon $page->nonAdmittedCategory
    // (D-051, jeu ferme de 10 valeurs). Un cas par categorie, phrase attendue ecrite en
    // clair (pas de reutilisation du tableau de app/View/word.php) pour verifier le
    // rendu independamment de l'implementation. badge/subtitle/title verifies identiques
    // sur un cas (proper_noun) -- seule la phrase "direct" doit varier.
    // -------------------------------------------------------------------
    $notAdmittedCategoryCases = [
        'proper_noun' => ['ABERDEEN', 'ABERDEEN est un nom propre — les noms propres ne sont jamais admis au Scrabble, quelle que soit leur notoriété.'],
        'acronym' => ['OVNI', 'OVNI est un sigle ou une abréviation, pas un mot du dictionnaire — les sigles ne sont pas admis au Scrabble.'],
        'slang' => ['CHELOU', 'CHELOU est un mot d’argot, absent des dictionnaires officiels du Scrabble.'],
        'colloquial' => ['BOBARD', 'BOBARD est un mot familier, non répertorié dans les dictionnaires officiels du Scrabble.'],
        'regional' => ['CHOCOLATINE', 'CHOCOLATINE est un régionalisme, employé dans certaines régions mais absent des dictionnaires officiels du Scrabble.'],
        'dialectal' => ['DRACHE', 'DRACHE est un mot dialectal, propre à un parler régional, non répertorié dans les dictionnaires officiels du Scrabble.'],
        'archaic' => ['OCCIRE', 'OCCIRE est une graphie ancienne, tombée en désuétude — les dictionnaires actuels du Scrabble ne la reconnaissent pas.'],
        'dated' => ['NENNI', 'NENNI est un mot vieilli, tombé en désuétude, absent des dictionnaires actuels du Scrabble.'],
        'obsolete' => ['CLEF', 'CLEF est une ancienne orthographe, remplacée depuis par une autre graphie, non reconnue par les dictionnaires actuels du Scrabble.'],
        'literary' => ['NYCTHEMERE', 'NYCTHEMERE est un mot littéraire rare, absent des dictionnaires officiels du Scrabble.'],
    ];

    foreach ($notAdmittedCategoryCases as $category => [$word, $expectedSentence]) {
        $letters = $tiles($word, $tileScores);
        $categoryPage = new TermPage(
            normalized: $word,
            slug: strtolower($word),
            found: true,
            status: TermPage::STATUS_FRENCH_NOT_ADMITTED,
            score: array_sum(array_column($letters, 'value')),
            length: strlen($word),
            isOds8: false,
            isOds9: false,
            letters: $letters,
            previousWord: null,
            nextWord: null,
            nonAdmittedCategory: $category,
        );
        $htmlCategory = $render($categoryPage, null, $noConjugation, $noSenses);

        Assert::true(
            str_contains($htmlCategory, $expectedSentence),
            $word . ' (' . $category . ') : phrase "Reponse Directe" specifique attendue (D-054)',
        );
    }

    // La valeur brute de nonAdmittedCategory (identifiant snake_case, ex. "proper_noun")
    // ne doit jamais fuiter telle quelle dans le HTML -- verifiee via l'absence de "_"
    // dans tout le rendu de cette fixture. NB : ne peut pas se verifier categorie par
    // categorie ci-dessus, car plusieurs adjectifs francais legitimes des phrases
    // attendues coincident avec le nom de la categorie (ex. "dialectal" est a la fois le
    // nom de la categorie et le mot francais correct dans sa propre phrase) -- seul
    // "proper_noun" (underscore, jamais present dans un rendu francais normal) permet ce
    // test sans faux positif.
    $properNounLetters = $tiles('ABERDEEN', $tileScores);
    $properNounLeakCheckPage = new TermPage(
        normalized: 'ABERDEEN',
        slug: 'aberdeen',
        found: true,
        status: TermPage::STATUS_FRENCH_NOT_ADMITTED,
        score: array_sum(array_column($properNounLetters, 'value')),
        length: 8,
        isOds8: false,
        isOds9: false,
        letters: $properNounLetters,
        previousWord: null,
        nextWord: null,
        nonAdmittedCategory: 'proper_noun',
    );
    $htmlProperNounLeakCheck = $render($properNounLeakCheckPage, null, $noConjugation, $noSenses);
    Assert::true(
        !str_contains($htmlProperNounLeakCheck, 'proper_noun'),
        'ABERDEEN : la valeur brute "proper_noun" ne doit jamais apparaitre dans le HTML rendu',
    );

    // badge/subtitle/title identiques a la fixture GHOSTER (nonAdmittedCategory NULL) --
    // seule la phrase "direct" doit varier selon la categorie.
    $aberdeenLetters = $tiles('ABERDEEN', $tileScores);
    $aberdeenPage = new TermPage(
        normalized: 'ABERDEEN',
        slug: 'aberdeen',
        found: true,
        status: TermPage::STATUS_FRENCH_NOT_ADMITTED,
        score: array_sum(array_column($aberdeenLetters, 'value')),
        length: 8,
        isOds8: false,
        isOds9: false,
        letters: $aberdeenLetters,
        previousWord: null,
        nextWord: null,
        nonAdmittedCategory: 'proper_noun',
    );
    $htmlAberdeen = $render($aberdeenPage, null, $noConjugation, $noSenses);
    Assert::true(str_contains($htmlAberdeen, '>Non Admis<'), 'ABERDEEN : badge "Non Admis" identique, inchange par D-054');
    Assert::true(str_contains($htmlAberdeen, 'Vous ne pouvez pas le jouer.'), 'ABERDEEN : sous-titre identique, inchange par D-054');
    Assert::true(str_contains($htmlAberdeen, 'Non, ABERDEEN N’est Pas Admis Au Scrabble'), 'ABERDEEN : titre identique (schema Non, %s N’est Pas Admis), inchange par D-054');

    // -------------------------------------------------------------------
    // Invariants communs a tous les cas (deja verifies en Phase 1, reconduits ici).
    // -------------------------------------------------------------------
    $cases = [
        $aaPage, $longPage, $notAdmittedPage, $unknownPage,
        $poserPage, $poseraPage, $tablePage, $chatPage, $etrePage, $formOnlyNotAdmittedPage,
    ];
    $htmlByCase = [
        $htmlAa, $htmlLong, $htmlNotAdmitted, $htmlUnknown,
        $htmlPoser, $htmlPosera, $htmlTable, $htmlChat, $htmlEtre, $htmlFormOnlyNotAdmitted,
    ];

    foreach ($cases as $i => $page) {
        $html = $htmlByCase[$i];

        $tileValueSum = array_sum(array_column($page->letters, 'value'));
        Assert::same($page->score, $tileValueSum, $page->normalized . ' : somme des tuiles doit egaler le score livre par TermPage');

        $letterTileCount = substr_count($html, 'class="letter-tile"');
        Assert::same($page->length, $letterTileCount, $page->normalized . ' : une tuile par lettre dans le HTML rendu');

        Assert::true(str_contains($html, $page->normalized), $page->normalized . ' : le mot doit apparaitre dans le HTML');
        Assert::true(str_contains($html, (string) $page->score), $page->normalized . ' : le score doit apparaitre dans le HTML');
        Assert::true(
            str_contains($html, '<form class="inline-check" action="/verifier" method="get">'),
            $page->normalized . ' : le formulaire de verification doit rester un GET natif sans JavaScript',
        );

        // D-015 : aucun credit de source publie, nulle part dans le HTML servi.
        $forbidden = ['kartmaan', 'hbenbel', 'github.com', 'larousse'];
        $lowerHtml = mb_strtolower($html);
        foreach ($forbidden as $needle) {
            Assert::true(!str_contains($lowerHtml, $needle), $page->normalized . ' : mention de source interdite (D-015) : ' . $needle);
        }
    }

    // Aucun mot precedent -> pas de lien mort, mais la structure reste symetrique.
    Assert::true(!str_contains($htmlAa, '← AA'), 'AA est le premier mot de la base : aucun lien precedent ne doit etre rendu');
    Assert::true(str_contains($htmlAa, 'AABAM'), 'le mot suivant doit rester present');

    Assert::true(!str_contains($htmlUnknown, 'ZZZQQQXXX →'), 'aucun mot suivant : aucun lien mort ne doit etre rendu');
};
