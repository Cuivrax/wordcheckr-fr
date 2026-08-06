<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Recherche d'un terme exact, mot precedent, mot suivant (Phase 1, docs/08).
 *
 * Budget : au plus 3 requetes SQLite par appel a find(), toutes avec LIMIT 1, toutes
 * servies par l'index UNIQUE sur normalized (sqlite_autoindex_terms_1) -- aucun scan,
 * voir reports/query-plans/phase1.md pour les plans et chronometrages. Une forme
 * d'entree invalide n'engendre aucune requete : find() renvoie null avant toute
 * ouverture de curseur.
 *
 * Les relations (anagrammes, sous-mots, rallonges...) sont hors perimetre de la
 * Phase 1 (Phase 4) ; les recherches liees statiques (longueur, prefixe...) egalement
 * (Phase 3).
 */
final class TermLookup
{
    /**
     * @param array<string, int> $tileScores
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $tileScores,
    ) {
    }

    /**
     * Normalise l'entree brute et construit la fiche mot correspondante.
     *
     * Renvoie null si la forme normalisee n'est pas un terme valide (2 a 15 lettres
     * A-Z) : c'est une erreur de saisie, pas un statut de terme (le modele a trois
     * statuts reste ferme), et elle n'engendre aucune requete SQLite. Au routeur de
     * traduire ce null en reponse 404.
     */
    public function find(string $rawInput): ?TermPage
    {
        $normalized = Normalizer::normalize($rawInput);

        if (!Normalizer::isValid($normalized)) {
            return null;
        }

        $row = $this->lookupRow($normalized);
        $found = $row !== null;

        $isOds8 = $found && (int) $row['is_ods8'] === 1;
        $isOds9 = $found && (int) $row['is_ods9'] === 1;

        $status = match (true) {
            $isOds8 || $isOds9 => TermPage::STATUS_ADMITTED,
            $found => TermPage::STATUS_FRENCH_NOT_ADMITTED,
            default => TermPage::STATUS_UNKNOWN,
        };

        $letters = $this->tiles($normalized);
        $score = $found ? (int) $row['score'] : Normalizer::score($normalized, $this->tileScores);
        $length = $found ? (int) $row['length'] : strlen($normalized);

        return new TermPage(
            normalized: $normalized,
            slug: strtolower($normalized),
            found: $found,
            status: $status,
            score: $score,
            length: $length,
            isOds8: $isOds8,
            isOds9: $isOds9,
            letters: $letters,
            previousWord: $this->neighbour($normalized, previous: true),
            nextWord: $this->neighbour($normalized, previous: false),
            // D-018 : uniquement pour un terme trouve -- $row est null sinon, ces trois
            // champs restent a leur defaut TermPage (null), jamais une fiche "inconnu"
            // avec une nature grammaticale.
            pos: $found ? $row['pos'] : null,
            posSecondary: $found ? $row['pos_secondary'] : null,
            gender: $found ? $row['gender'] : null,
        );
    }

    /**
     * @return array{display_term: string, score: string|int, length: string|int, is_ods8: string|int, is_ods9: string|int, pos: string|null, pos_secondary: string|null, gender: string|null}|null
     */
    private function lookupRow(string $normalized): ?array
    {
        // D-018 : pos/pos_secondary/gender ajoutes a ce SELECT existant -- ZERO requete
        // SQLite supplementaire (meme requete, meme plan que Phase 1 : recherche sur
        // l'index unique sqlite_autoindex_terms_1, puis lecture de la ligne complete,
        // deja necessaire pour display_term/score qui ne sont pas non plus couverts par
        // cet index).
        $statement = $this->connection->pdo()->prepare(
            'SELECT display_term, score, length, is_ods8, is_ods9, pos, pos_secondary, gender '
            . 'FROM terms WHERE normalized = ? LIMIT 1'
        );
        $statement->execute([$normalized]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function neighbour(string $normalized, bool $previous): ?string
    {
        $sql = $previous
            ? 'SELECT normalized FROM terms WHERE normalized < ? ORDER BY normalized DESC LIMIT 1'
            : 'SELECT normalized FROM terms WHERE normalized > ? ORDER BY normalized ASC LIMIT 1';

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute([$normalized]);
        $row = $statement->fetch();

        return $row === false ? null : $row['normalized'];
    }

    /**
     * Defense en profondeur (audit Phase 1, C2) : une lettre absente de
     * $this->tileScores leve plutot que de produire une valeur nulle silencieuse --
     * voir la meme regle dans Normalizer::score().
     *
     * @return list<array{letter: string, value: int}>
     */
    private function tiles(string $normalized): array
    {
        $tiles = [];

        foreach (str_split($normalized) as $letter) {
            if (!array_key_exists($letter, $this->tileScores)) {
                throw new \InvalidArgumentException(sprintf('Lettre sans valeur de tuile : %s', $letter));
            }

            $tiles[] = ['letter' => $letter, 'value' => $this->tileScores[$letter]];
        }

        return $tiles;
    }
}
