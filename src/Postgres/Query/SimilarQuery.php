<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Normalization\Normalizer;
use Happenv\ModelSearch\Normalization\PhraseShape;
use Happenv\ModelSearch\Normalization\QueryVariants;
use Happenv\ModelSearch\Postgres\Schema\IndexPlan;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\SearchQuery;

/**
 * "Search for similar": the one place the `Fuzzy` mode is read (never the palette, never a table).
 *
 * A single statement over the entries: the records whose normalized names contain a word that is
 * similar to a word of the phrase (`<%`, the GIN trigram index over `names`, at the
 * `word_similarity_threshold` of the configuration), joined with the viewer's scope by the primary
 * key, ranked by the best similarity, then by `sortAt`, and cut at the limit AFTER the scope. Every stage is
 * read: the entry's stage only tells which stage a record belongs to.
 *
 * It matches ALL the normalized names in `entries.names`, including those of name fields that did not
 * declare `Fuzzy` (the column does not record which field a name came from; the same root as the
 * refusal of a strict subset of the name fields).
 *
 * The value of a match is the name behind the most similar name word of the record, so it is
 * revealed under the rule of the name words (every name field must allow it). A definition without
 * name words (no name field that matches by prefix) reports the match without a value.
 *
 * @internal
 */
final class SimilarQuery
{
    /** The shortest normalized phrase worth a trigram lookup. */
    private const int MIN_LENGTH = 3;

    /**
     * @return array{sql: string, bindings: list<mixed>}|null Null when nothing can match: no fuzzy name field, an identifier-shaped phrase, a phrase too short.
     */
    public static function sql(CompiledDefinition $definition, SearchQuery $query): ?array
    {
        $fields = self::fields($definition, $query);
        $phrase = $query->phraseText();

        if ($fields === [] || PhraseShape::isIdentifierLike($phrase)) {
            return null;
        }

        $forms = self::forms($fields, $phrase, $query->viewerLocale());

        if ($forms === []) {
            return null;
        }

        $model = new ($definition->modelClass);
        $names = TableNames::forConnection(Sql::connectionName($model));
        $entries = $names->quoted($names->entries());
        $keys = $names->quoted($names->keys());
        $host = $query->scope()->sql();
        $partition = $query->partitionFilter();
        $words = self::hasWords($definition);

        $similarity = implode(', ', array_map(static fn (): string => 'word_similarity(CAST(? AS text), e.names)', $forms));
        $matching = implode(' OR ', array_map(static fn (): string => 'CAST(? AS text) <% e.names', $forms));
        $wordSimilarity = implode(', ', array_map(static fn (): string => 'word_similarity(CAST(? AS text), w.key)', $forms));

        $value = 'CAST(NULL AS text)';
        $valueBindings = [];

        if ($words) {
            $value = '(SELECT w.value FROM '.$keys.' w WHERE w.subject_type = ? AND w.subject_id = r.subject_id AND w.'.IndexPlan::NAME_WORDS.' ORDER BY greatest('.$wordSimilarity.') DESC, w.key LIMIT 1)';
            $valueBindings = [$definition->morphAlias, ...$forms];
        }

        $sql = sprintf(
            'SELECT r.subject_id, %1$s AS value, (r.stage < %2$d) AS in_stage, r.sim FROM (SELECT c.subject_id, c.stage, c.sim, c.sort_at FROM (SELECT e.subject_id, e.stage, e.sort_at, greatest(%3$s) AS sim FROM %4$s e WHERE e.subject_type = ?%5$s AND (%6$s)) AS c JOIN (%7$s) AS h ON h.%8$s = c.subject_id::%9$s ORDER BY c.sim DESC, c.sort_at DESC NULLS LAST, c.subject_id LIMIT %10$d) AS r ORDER BY r.sim DESC, r.sort_at DESC NULLS LAST, r.subject_id',
            $value,
            CompiledDefinition::ALL_STAGE,
            $similarity,
            $entries,
            $partition === null ? '' : ' AND e."partition" = ?',
            $matching,
            $host['sql'],
            Sql::ident(HostQuery::KEY_ALIAS),
            $definition->resolvedKeySqlType(),
            $query->maxResults(),
        );

        // The placeholders appear in the text in this order: the value subquery, the similarity list, the type, the partition, the matching list, the host.
        return [
            'sql' => $sql,
            'bindings' => [...$valueBindings, ...$forms, $definition->morphAlias, ...($partition === null ? [] : [(string) $partition['value']]), ...$forms, ...$host['bindings']],
        ];
    }

    /**
     * The ranked rows of the statement: subject, the revealable value, whether it is in the first stage.
     *
     * @param  list<object>  $rows
     * @return list<CandidateRow>
     */
    public static function read(CompiledDefinition $definition, array $rows): array
    {
        $kind = self::hasWords($definition) ? SearchField::RESERVED_KIND : (self::firstFuzzy($definition)->kind ?? SearchField::RESERVED_KIND);
        $candidates = [];

        foreach ($rows as $row) {
            $columns = (array) $row;
            $subject = $columns['subject_id'] ?? '';
            $value = $columns['value'] ?? null;
            $inStage = $columns['in_stage'] ?? false;

            $candidates[] = new CandidateRow(
                is_scalar($subject) ? (string) $subject : '',
                $kind,
                Mode::Fuzzy->rank(),
                is_string($value) ? $value : null,
                null,
                in_array($inStage, [true, 't', 1, '1'], true),
            );
        }

        return $candidates;
    }

    /**
     * The name fields a similar search reads: the ones that declared `Fuzzy` and that `only()` did not leave out.
     *
     * @return list<CompiledField>
     */
    private static function fields(CompiledDefinition $definition, SearchQuery $query): array
    {
        $kinds = $query->onlyKinds();

        return array_values(array_filter(
            $definition->fields,
            static fn (CompiledField $field): bool => $field->type === FieldType::Name
                && $field->compiledPath instanceof CompiledPath
                && in_array(Mode::Fuzzy, $field->modes, true)
                && ($kinds === [] || in_array($field->kind, $kinds, true)),
        ));
    }

    /**
     * The normalized forms of the phrase, the way the names were normalized when they were indexed.
     *
     * @param  list<CompiledField>  $fields
     * @return list<string>
     */
    private static function forms(array $fields, #[\SensitiveParameter] string $phrase, ?string $locale): array
    {
        $forms = [];

        if (array_any($fields, static fn (CompiledField $field): bool => ! $field->normalizer instanceof Normalizer)) {
            $forms = QueryVariants::for($phrase, $locale);
        }

        foreach ($fields as $field) {
            if ($field->normalizer instanceof Normalizer) {
                $forms[] = $field->normalizer->normalize($phrase);
            }
        }

        return array_values(array_unique(array_filter(
            $forms,
            static fn (string $form): bool => mb_strlen($form) >= self::MIN_LENGTH,
        )));
    }

    /**
     * Whether the definition keeps name words (a name field that matches by prefix).
     */
    private static function hasWords(CompiledDefinition $definition): bool
    {
        return array_any($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true));
    }

    private static function firstFuzzy(CompiledDefinition $definition): ?CompiledField
    {
        return array_find($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Fuzzy, $field->modes, true));
    }
}
