<?php

declare(strict_types=1);

use App\Seo\Family;
use Tests\Support\Assert;

/**
 * App\Seo\Family : liste fermee des familles de reporting/gouvernance et les deux regles
 * dures qui en decoulent (combinaisons infinies jamais dans un sitemap, francais non admis
 * jamais en masse) -- verifie ici independamment de toute base de donnees, meme esprit que
 * tests/Search/WordListFiltersTest.php pour App\Search\WordListFilters.
 */
return function (): void {
    Assert::true(Family::isValid(Family::WORD_ADMITTED));
    Assert::true(Family::isValid(Family::HOME));
    Assert::true(!Family::isValid('mot_inconnu'));
    Assert::true(!Family::isValid(''));

    // Chaque valeur de ALL doit etre reconnue par isValid() -- coherence interne.
    foreach (Family::ALL as $family) {
        Assert::true(Family::isValid($family), "famille declaree mais non reconnue : {$family}");
    }

    // Combinaisons infinies : jamais de sitemap, quel que soit le lot (R4 de
    // scripts/apply_seo_batch.php).
    $expectedForbidden = [
        Family::WORD_LIST_CONTENANT,
        Family::WORD_LIST_AVEC,
        Family::WORD_LIST_SANS,
        Family::WORD_LIST_MOTIF,
        Family::WORD_LIST_COMBINED,
        Family::RACK,
    ];

    foreach ($expectedForbidden as $family) {
        Assert::true(Family::forbidsSitemap($family), "attendu interdit de sitemap : {$family}");
    }

    $expectedAllowed = [
        Family::HOME,
        Family::WORD_ADMITTED,
        Family::WORD_FRENCH_NOT_ADMITTED,
        Family::WORD_LIST_LENGTH,
        Family::WORD_LIST_COMMENCANT,
        Family::WORD_LIST_TERMINANT,
    ];

    foreach ($expectedAllowed as $family) {
        Assert::true(!Family::forbidsSitemap($family), "ne devrait pas etre interdit de sitemap : {$family}");
    }

    // Seule word_french_not_admitted porte la contrainte "jamais en masse".
    Assert::true(Family::isFrenchNotAdmitted(Family::WORD_FRENCH_NOT_ADMITTED));

    foreach (Family::ALL as $family) {
        if ($family === Family::WORD_FRENCH_NOT_ADMITTED) {
            continue;
        }

        Assert::true(!Family::isFrenchNotAdmitted($family), "ne devrait pas etre francais non admis : {$family}");
    }

    // Plafond revise le 2026-08-04 (D-017, docs/DECISIONS.md) : decision explicite du
    // propriétaire du produit d'ouvrir l'indexation du francais non admis, le site
    // n'etant pas encore deploye (aucun risque Google reel avant la Phase 7). Le volume
    // change, l'attestation ligne par ligne (R6/R7 : notes non vide) reste obligatoire.
    Assert::true(Family::MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED > 0);
    Assert::true(
        Family::MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED >= 435_120,
        'le plafond doit couvrir la totalite des formes francaises non admises connues (D-017)',
    );
};
