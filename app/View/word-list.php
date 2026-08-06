<?php

declare(strict_types=1);

/**
 * Vue listes de mots /mots/..., appelee par public/index.php avec $page
 * (App\Search\WordListPage, Phase 3). Meme patron que app/View/play.php et
 * app/View/word.php (reponse directe presente sans JavaScript, .status-badge
 * reutilise tel quel, aucun credit de source -- D-015).
 *
 * Deux regimes distincts (voir App\Search\WordListPage) :
 * - exact = true  : $page->total est un compte EXACT, pagination fiable.
 *   total = 0 est un cas distinct ("aucun mot"), jamais confondu avec le cas
 *   tronque ci-dessous.
 * - exact = false (donc $page->truncated = true) : $page->total est un compte
 *   trouve dans une fenetre bornee (WordListSolver::ROW_EXAMINATION_CEILING),
 *   jamais presente comme un chiffre definitif -- formulation "au moins N mots"
 *   dans le meme esprit que le cas RackPage::$capped de /jouer/{lettres}.
 *
 * Le H1 et le paragraphe de reponse directe sont construits a partir de
 * App\Search\WordListFilters::fromPath($page->canonicalPath) -- reparse pure
 * (aucun acces base), qui redonne les contraintes actives dans l'ordre
 * canonique impose (docs/05) pour produire un titre lisible combinant
 * plusieurs contraintes. Les liens de pagination reutilisent
 * WordListFilters::canonicalUrl() plutot que de reconstruire l'URL a la main,
 * pour rester byte-identiques a ce que public/index.php attend comme forme
 * canonique (evite tout aller-retour de redirection 301).
 *
 * Aucun formulaire de construction de requete ici (aucune UI de ce type
 * documentee dans docs/04) -- page de resultats uniquement, atteinte par URL
 * directe. Le mini-formulaire "verifier un mot" est repris tel quel (meme
 * composant .inline-check que sur toutes les autres vues), pas un
 * constructeur de filtres.
 */

require __DIR__ . '/helpers.php';

use App\Search\TermPage;
use App\Search\WordListFilters;
use App\Search\WordListPage;

/** @var WordListPage $page */
/** @var \App\Seo\SeoMeta $seo */

$filters = WordListFilters::fromPath($page->canonicalPath);

$pageUrl = static function (int $targetPage) use ($page): string {
    $path = $page->canonicalPath . ($targetPage > 1 ? '/page/' . $targetPage : '');
    $targetFilters = WordListFilters::fromPath($path);

    return $targetFilters?->canonicalUrl() ?? '/mots';
};

// Chaine de pagination en nofollow quand la liste n'a AUCUN ancrage indexe (ni longueur, ni
// debut, ni fin) -- audit final, 4e passe, code-reviewer, constat I-1 : sans ancrage,
// WordListSolver::solveBounded() parcourt l'index dans son integralite (exception bornee et
// documentee, docs/DECISIONS.md D-019) ; suivre Precedent/Suivant sur ces listes rejoue ce
// parcours a chaque page (jusqu'a 200 pages), rouvrant automatiquement pour un robot le meme
// risque de crawl que les liens auto-generes deja retires ailleurs (RelationsFinder). Les
// listes ancrees (longueur/debut/fin) restent en follow : elles servent de chemin de crawl
// legitime vers les fiches mots (D-017), et leur cout par page est deja borne par un index.
$isAnchored = $filters !== null && ($filters->length !== null || $filters->prefix !== null || $filters->suffix !== null);
$paginationRel = $isAnchored ? '' : ' rel="nofollow"';

// Titre lisible, ordre canonique impose (docs/05) : longueur -> commencant ->
// contenant -> terminant -> avec -> sans -> motif ("position" hors perimetre).
$titleParts = [];

if ($filters !== null && $filters->length !== null) {
    $titleParts[] = sprintf('de %d lettre%s', $filters->length, $filters->length > 1 ? 's' : '');
}

if ($filters !== null && $filters->prefix !== null) {
    $titleParts[] = 'commençant par ' . $filters->prefix;
}

if ($filters !== null && $filters->contains !== null) {
    $titleParts[] = 'contenant ' . $filters->contains;
}

if ($filters !== null && $filters->suffix !== null) {
    $titleParts[] = 'terminant par ' . $filters->suffix;
}

if ($filters !== null && $filters->withLetters !== []) {
    $withLetters = [];
    foreach ($filters->withLetters as $letter => $count) {
        for ($k = 0; $k < $count; $k++) {
            $withLetters[] = $letter;
        }
    }
    $titleParts[] = 'avec ' . implode(', ', $withLetters);
}

if ($filters !== null && $filters->withoutLetters !== []) {
    $titleParts[] = 'sans ' . implode(', ', $filters->withoutLetters);
}

if ($filters !== null && $filters->pattern !== null) {
    $titleParts[] = 'au motif ' . $filters->pattern;
}

$descriptor = implode(' ', $titleParts);
$pageTitle = trim('Mots ' . $descriptor);

// Reponse directe : trois cas distincts, jamais confondus (voir doc de tete).
// $page->truncated est teste EN PREMIER, avant $page->total === 0 : un panier
// tronque avec 0 resultat DANS LA FENETRE EXAMINEE n'est pas la meme chose
// qu'un "aucun mot" exact -- confondre les deux affirmerait a tort une absence
// definitive alors que d'autres correspondances pourraient exister au-dela de
// WordListSolver::ROW_EXAMINATION_CEILING.
$statusMeta = match (true) {
    $page->truncated => [
        'modifier' => 'admitted',
        'badge' => 'Liste partielle',
        'subtitle' => 'Liste partielle, non exhaustive.',
        'direct' => $page->total > 0
            ? sprintf(
                'Au moins %d mot%s %s %s trouvé%s dans la partie examinée. La liste n’est pas garantie complète au-delà de cette limite.',
                $page->total,
                $page->total > 1 ? 's' : '',
                $descriptor,
                $page->total > 1 ? 'ont été' : 'a été',
                $page->total > 1 ? 's' : '',
            )
            : sprintf(
                'Aucun mot %s trouvé dans la partie examinée. La liste n’est pas garantie complète au-delà de cette limite.',
                $descriptor,
            ),
    ],
    $page->total === 0 => [
        'modifier' => 'unknown',
        'badge' => 'Aucun mot',
        'subtitle' => 'Aucun mot trouvé.',
        'direct' => sprintf('Aucun mot %s n’a été trouvé dans la base.', $descriptor),
    ],
    $page->total === 1 => [
        'modifier' => 'admitted',
        'badge' => 'Mot trouvé',
        'subtitle' => 'Liste classée par ordre alphabétique.',
        'direct' => sprintf('Il y a 1 mot %s.', $descriptor),
    ],
    default => [
        'modifier' => 'admitted',
        'badge' => 'Mots trouvés',
        'subtitle' => 'Liste classée par ordre alphabétique.',
        'direct' => sprintf('Il y a %d mots %s.', $page->total, $descriptor),
    ],
};

// Statut par ligne : memes trois valeurs fermees que la fiche mot (jamais
// STATUS_UNKNOWN ici, voir WordListSolver::toItems()). Texte minimal, a
// confirmer par l'agent microcopy -- meme convention que app/View/word.php.
$rowStatusMeta = static fn (string $status): array => $status === TermPage::STATUS_ADMITTED
    ? ['modifier' => 'admitted', 'label' => 'Admis']
    : ['modifier' => 'not-admitted', 'label' => 'Non admis'];

$showPagination = $page->hasPreviousPage || $page->hasNextPage;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="<?= e($seo->robots) ?>">
<title><?= e($pageTitle) ?> &middot; Mot Direct</title>
<meta name="description" content="<?= e($statusMeta['direct']) ?>">
<?php if ($seo->canonicalUrl !== null): ?>
<link rel="canonical" href="<?= e($seo->canonicalUrl) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<a class="skip-link" href="#main">Aller au contenu</a>
<header class="header">
  <div class="site header-row">
    <a class="logo" href="/">MOT DIRECT</a>
    <nav class="nav" aria-label="Navigation principale"><a href="/">Nouvelle recherche</a></nav>
  </div>
</header>

<main class="word-shell main" id="main">
  <nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="/">Accueil</a> › <?= e($pageTitle) ?></nav>

  <article class="word-card">
    <section class="word-answer">
      <span class="status-badge status-badge--<?= e($statusMeta['modifier']) ?>"><?= e($statusMeta['badge']) ?></span>
      <h1 class="word-title explore-title"><?= e($pageTitle) ?></h1>
      <p><?= e($statusMeta['subtitle']) ?></p>
    </section>

    <section class="direct">
      <h2>Réponse Directe</h2>
      <p><?= e($statusMeta['direct']) ?></p>
    </section>

<?php if ($page->items !== []): ?>
    <section class="rack-results">
<?php if ($page->truncated): ?>
      <p class="help rack-results-note">Résultats trouvés dans une fenêtre bornée, non exhaustifs au-delà de cette limite.</p>
<?php endif; ?>
      <div class="rack-result-head" aria-hidden="true">
        <span>Mot</span><span>Statut</span><span>Points</span><span class="rack-result-length">Lettres</span>
      </div>
      <ul class="rack-result-list">
<?php foreach ($page->items as $item): ?>
<?php $rowStatus = $rowStatusMeta($item['status']); ?>
        <li class="rack-result-row">
          <a class="rack-result-word" href="/mot/<?= e($item['slug']) ?>"><?= e($item['normalized']) ?></a>
          <span class="status-badge status-badge--<?= e($rowStatus['modifier']) ?>"><?= e($rowStatus['label']) ?></span>
          <span class="rack-result-points" aria-label="<?= e($item['score']) ?> points"><?= e($item['score']) ?></span>
          <span class="rack-result-length" aria-label="<?= e($item['length']) ?> lettres"><?= e($item['length']) ?></span>
        </li>
<?php endforeach; ?>
      </ul>
    </section>
<?php elseif ($page->total > 0): ?>
    <!-- Page demandee au-dela de la derniere page existante (total > 0 mais
         cette page precise n'a aucune ligne) : message distinct du cas "aucun
         mot" (qui ne s'affiche que lorsque total = 0, voir $statusMeta
         ci-dessus) -- evite une section resultats silencieusement vide. -->
    <p class="help rack-results-note">Aucun mot sur cette page.</p>
<?php endif; ?>

<?php if ($showPagination): ?>
    <nav class="word-nav" aria-label="Pagination">
<?php if ($page->hasPreviousPage): ?>
      <a href="<?= e($pageUrl($page->page - 1)) ?>"<?= $paginationRel ?>>← Précédent</a>
<?php else: ?>
      <span></span>
<?php endif; ?>
      <span class="help">Page <?= e($page->page) ?></span>
<?php if ($page->hasNextPage): ?>
      <a href="<?= e($pageUrl($page->page + 1)) ?>"<?= $paginationRel ?>>Suivant →</a>
<?php else: ?>
      <span></span>
<?php endif; ?>
    </nav>
<?php endif; ?>

    <form class="inline-check" action="/verifier" method="get">
      <label class="sr-only" for="mot-check">Vérifier un mot</label>
      <input class="field" type="text" id="mot-check" name="mot" maxlength="15" autocomplete="off" spellcheck="false" placeholder="Vérifier un mot">
      <button class="btn btn-primary" type="submit">Vérifier</button>
    </form>
  </article>
</main>

<footer class="footer">
  <div class="word-shell footer-row">
    <span>Outil indépendant d’aide aux jeux de lettres.</span>
  </div>
</footer>
</body>
</html>
