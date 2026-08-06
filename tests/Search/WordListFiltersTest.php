<?php

declare(strict_types=1);

use App\Search\WordListFilters;
use Tests\Support\Assert;

/**
 * App\Search\WordListFilters : analyse et canonicalisation des contraintes de /mots/...
 * (Phase 3), independamment de toute base de donnees -- meme esprit que RackTest.php pour
 * App\Search\Rack.
 */
return function (): void {
    // --- Longueur seule. ---
    $length = WordListFilters::fromPath('7-lettres');
    Assert::notNull($length);
    Assert::same(7, $length->length);
    Assert::true($length->isEmpty() === false);
    Assert::same('/mots/7-lettres', $length->canonicalUrl());

    // --- Prefixe seul, insensible a la casse et aux accents (Normalizer::normalize, D-009). ---
    $prefix = WordListFilters::fromPath('commencant/CH');
    Assert::notNull($prefix);
    Assert::same('CH', $prefix->prefix);
    Assert::same('/mots/commencant/ch', $prefix->canonicalUrl());

    $accentedPrefix = WordListFilters::fromPath('commencant/éÉ');
    Assert::notNull($accentedPrefix);
    Assert::same('EE', $accentedPrefix->prefix, 'accents retires par Normalizer::normalize()');

    // --- Longueur + prefixe combines, dans l'ordre canonique recu (deja correct ici). ---
    $combo = WordListFilters::fromPath('7-lettres/commencant/ch');
    Assert::notNull($combo);
    Assert::same(7, $combo->length);
    Assert::same('CH', $combo->prefix);
    Assert::same('/mots/7-lettres/commencant/ch', $combo->canonicalUrl());

    // --- Terminant. ---
    $suffix = WordListFilters::fromPath('terminant/tion');
    Assert::notNull($suffix);
    Assert::same('TION', $suffix->suffix);

    // --- Contenant. ---
    $contains = WordListFilters::fromPath('contenant/che');
    Assert::notNull($contains);
    Assert::same('CHE', $contains->contains);

    // --- Avec : repetitions comptees, triees par lettre. ---
    $with = WordListFilters::fromPath('avec/a/a/r');
    Assert::notNull($with);
    Assert::same(['A' => 2, 'R' => 1], $with->withLetters);
    Assert::same('/mots/avec/a/a/r', $with->canonicalUrl(), 'ordre canonique alphabetique, repetitions regroupees');

    // Ordre de saisie sans effet sur le resultat (meme principe que Rack : multiensemble).
    $withReordered = WordListFilters::fromPath('avec/r/a/a');
    Assert::notNull($withReordered);
    Assert::same($with->withLetters, $withReordered->withLetters);
    Assert::same($with->canonicalUrl(), $withReordered->canonicalUrl());

    // "avec" sans aucune lettre : entree malformee, pas un resultat vide.
    Assert::null(WordListFilters::fromPath('avec'));

    // --- Sans : lettres distinctes, sans notion de repetition, deduplique et triees. ---
    $without = WordListFilters::fromPath('sans/z/x/z');
    Assert::notNull($without);
    Assert::same(['X', 'Z'], $without->withoutLetters);

    // --- Motif : longueur derivee, prefixe initial detecte, cases connues preservees. ---
    $pattern = WordListFilters::fromPath('5-lettres/motif/c--e-');
    Assert::notNull($pattern);
    Assert::same('C--E-', $pattern->pattern);
    Assert::same(5, $pattern->length, 'la longueur du motif prevaut');
    Assert::true($pattern->needsUnindexedPredicates(), 'ce motif a une case connue (E) au-dela du prefixe initial (C) -> predicat non indexe necessaire');

    // Motif entierement fait de '-' : refuse, n'apporte rien qu'une longueur ne dise deja.
    Assert::null(WordListFilters::fromPath('5-lettres/motif/-----'));

    // Motif dont la longueur explicite ne correspond pas au segment "{N}-lettres" fourni :
    // pas une 404, la longueur du motif prevaut -- l'URL canonique se corrige elle-meme,
    // et le routeur redirige en 301 (meme esprit que toute autre permutation, docs/05).
    $mismatched = WordListFilters::fromPath('6-lettres/motif/c--e-');
    Assert::notNull($mismatched);
    Assert::same(5, $mismatched->length);
    Assert::same('/mots/5-lettres/motif/c--e-', $mismatched->canonicalUrl());

    // --- Canonicalisation : ordre impose quel que soit l'ordre recu (docs/05). ---
    $permuted = WordListFilters::fromPath('terminant/tion/commencant/ch');
    Assert::notNull($permuted);
    Assert::same('/mots/commencant/ch/terminant/tion', $permuted->canonicalUrl());

    $fullOrder = WordListFilters::fromPath('sans/z/7-lettres/motif/-------/avec/a/commencant/a');
    // motif tout-tirets refuse plus haut dans la chaine -> attendu null ici aussi (verifie
    // que le refus d'un segment ne laisse pas les autres segments partiellement acceptes).
    Assert::null($fullOrder);

    // --- Pagination : page 1 jamais dans l'URL, page 1 explicite redirige (pas 404). ---
    $noPage = WordListFilters::fromPath('7-lettres');
    Assert::notNull($noPage);
    Assert::same(1, $noPage->page);
    Assert::same('/mots/7-lettres', $noPage->canonicalUrl(), 'page 1 jamais refletee dans l\'URL');

    $explicitPageOne = WordListFilters::fromPath('7-lettres/page/1');
    Assert::notNull($explicitPageOne, 'page/1 est syntaxiquement valide, pas une entree malformee');
    Assert::same(1, $explicitPageOne->page);
    Assert::same('/mots/7-lettres', $explicitPageOne->canonicalUrl(), 'redirige vers la forme sans /page/1, jamais 404');

    $pageTwo = WordListFilters::fromPath('7-lettres/page/2');
    Assert::notNull($pageTwo);
    Assert::same(2, $pageTwo->page);
    Assert::same('/mots/7-lettres/page/2', $pageTwo->canonicalUrl());

    Assert::null(WordListFilters::fromPath('7-lettres/page/0'), 'page 0 invalide');
    Assert::null(WordListFilters::fromPath('7-lettres/page/-1'), 'page negative invalide');
    Assert::null(WordListFilters::fromPath('7-lettres/page/deux'), 'page non numerique invalide');

    // --- Rejets : hors perimetre, malformes, ou hors bornes -- toujours null, jamais d'exception. ---
    Assert::null(WordListFilters::fromPath('position/3/r'), '"position" hors perimetre de cette phase (absent de docs/08)');
    Assert::null(WordListFilters::fromPath('commencant/ch/commencant/ta'), 'mot-cle "commencant" duplique');
    Assert::null(WordListFilters::fromPath('20-lettres'), 'longueur au-dessus de la borne D-010 (15)');
    Assert::null(WordListFilters::fromPath('1-lettres'), 'longueur en dessous de la borne (2)');
    Assert::null(WordListFilters::fromPath('commencant'), 'mot-cle sans valeur');
    Assert::null(WordListFilters::fromPath('inconnu/valeur'), 'mot-cle non reconnu');
    Assert::null(WordListFilters::fromPath('avec/ab'), 'segment "avec" de plus d\'une lettre');
    Assert::null(WordListFilters::fromPath("avec/\xFF\xFE"), 'octets UTF-8 invalides');
    Assert::null(WordListFilters::fromPath('commencant/ch/7-lettres'), 'longueur doit ouvrir le chemin, jamais apparaitre ailleurs');

    // --- Chemin vide : etat interne valide (isEmpty), mais WordListSolver le refuse
    // --- explicitement (hors perimetre de docs/05, jamais expose comme route). ---
    $empty = WordListFilters::fromPath('');
    Assert::notNull($empty);
    Assert::true($empty->isEmpty());
};
