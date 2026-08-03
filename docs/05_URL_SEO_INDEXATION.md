# 05 — URL, SEO Et Indexation

## Registre Unique

Le registre SEO est l’unique source de vérité pour :

```text
index ou noindex
canonical
sitemaps
maillage interne
rollout
métadonnées
```

Une route absente du registre reste :

```text
noindex, follow
```

## Routes Principales

```text
/
/mot/qi
/mot/poser
/jouer/aeinrst
/mots/7-lettres
/mots/commencant/ch
/mots/7-lettres/commencant/ch
/mots/terminant/tion
/mots/contenant/che
/mots/avec/a/a/r
/mots/5-lettres/motif/c--e-
```

## Ordre Canonique

```text
longueur
commençant
contenant
terminant
position
avec
sans
motif
```

Toute autre permutation redirige en 301.

## Fiches Françaises

Après filtrage, toutes les formes `is_french = 1` ont vocation à être
indexables.

L’ouverture reste progressive :

```text
lot initial
lots supplémentaires
contrôle o2switch
contrôle Search Console
```

Une forme française non ODS affiche une réponse négative utile et peut être
indexée.

Un terme absent de la base reste noindex.

## Pages À Un Résultat

Une page avec un résultat n’est pas automatiquement faible.

Décision basée sur :

```text
famille autorisée
intention claire
canonical correct
maillage réel
réponse utile
```

Jamais sur le seul compteur.

## Sitemaps

```text
sitemap-index.xml
words-*.xml
invalid-french-*.xml
starts-*.xml
ends-*.xml
contains-*.xml
letters-*.xml
```

Limite interne :

```text
40 000 URL par fragment
```

Chaque URL du sitemap doit répondre :

```text
200
index
canonical autonome
contenu non vide
aucune redirection
```

## Pagination

```text
/page/2
/page/3
```

Canonical autonome et vrais liens précédent/suivant.

Les tris et paramètres ne sont pas indexables.
