<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\SuffixAvecLinks depuis list_counts (list_type 'end_with'), symetrique de
 * App\Search\PrefixAvecLinksBuilder cote suffixe (Family::WORD_LIST_TERMINANT_WITH_LETTER,
 * famille entierement nouvelle, D-045) -- une seule requete triviale, aucun calcul sur `terms`
 * au runtime.
 *
 * list_key est toujours "{suffixe}:{lettre}" pour 'end_with' -- une seule direction de lecture
 * necessaire (la page source est toujours /mots/terminant/{X}).
 *
 * Les combinaisons degenerees (lettre "avec" = suffixe, D-032) sont deja absentes de
 * list_counts -- exclues AU PRECALCUL (scripts/build_explore_hub_counts.php), meme discipline
 * que 'start_with'/App\Search\PrefixAvecLinksBuilder.
 */
final class SuffixAvecLinksBuilder
{
    /**
     * Doublons de CONTENU avec la page PARENTE (D-045, meme methodologie que
     * App\Search\PrefixAvecLinksBuilder::DUPLICATE_CONTENT_KEYS) -- voir docs/DECISIONS.md
     * D-045.
     *
     * @var list<string>
     */
    private const DUPLICATE_CONTENT_KEYS = [];

    /**
     * Doublons de contenu entre pages SOEURS du meme suffixe (D-045, meme methodologie que
     * App\Search\PrefixAvecLinksBuilder::SIBLING_DUPLICATE_KEYS) -- voir docs/DECISIONS.md
     * D-045.
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE (D-045, meme discipline D-041) --
     * voir docs/DECISIONS.md D-045.
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $suffix): SuffixAvecLinks
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'end_with' AND list_key LIKE ?"
        );
        $statement->execute([$suffix . ':%']);

        $parentUrl = WordListFilters::fromPath('terminant/' . strtolower($suffix))?->canonicalUrl();

        $links = [];

        foreach ($statement as $row) {
            [, $letter] = explode(':', (string) $row['list_key'], 2);

            $path = 'terminant/' . strtolower($suffix) . '/avec/' . strtolower($letter);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url === null || $url === $parentUrl) {
                continue;
            }

            $key = strtoupper($suffix) . ':' . strtoupper($letter);

            if (
                in_array($key, self::DUPLICATE_CONTENT_KEYS, true)
                || in_array($key, self::SIBLING_DUPLICATE_KEYS, true)
                || in_array($key, self::EXTERNAL_DUPLICATE_KEYS, true)
            ) {
                continue;
            }

            $links[] = ['letter' => $letter, 'url' => $url, 'count' => (int) $row['count']];
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new SuffixAvecLinks(links: $links, queryCount: 1);
    }
}
