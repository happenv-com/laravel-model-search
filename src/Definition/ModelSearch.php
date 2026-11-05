<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Query\SearchQuery;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * One class per searchable model. The model is named by the {@see SearchesModel} attribute.
 */
abstract class ModelSearch
{
    /**
     * @return list<SearchField>
     */
    abstract public function fields(): array;

    /**
     * Ordered narrowings of the model's query; at most one besides {@see SearchStage::all()}.
     *
     * @return list<SearchStage>
     */
    public function stages(): array
    {
        return [SearchStage::all()];
    }

    /**
     * Column holding the moment used to order results of equal rank.
     */
    public function sortAt(): ?string
    {
        return null;
    }

    /**
     * Column that partitions the index (for single-database multi-tenancy); null falls back to the config.
     */
    public function partition(): ?string
    {
        return null;
    }

    /**
     * Whether the database driver may serve this model when no index is ready.
     */
    public function fallback(): bool
    {
        return false;
    }

    /**
     * Bump to force a rebuild when the definition's behaviour changes in a way the fingerprint cannot see.
     */
    public function version(): int
    {
        return 1;
    }

    /**
     * Global scopes (besides soft deletes) that still apply while paths are indexed.
     *
     * @return list<class-string>
     */
    public function withScopes(): array
    {
        return [];
    }

    /**
     * Starts a search of this definition's model for a phrase typed by a user.
     */
    final public static function query(#[\SensitiveParameter] string $phrase): SearchQuery
    {
        return new SearchQuery(self::model(), $phrase);
    }

    /**
     * @return class-string<Model>
     */
    final public static function model(): string
    {
        $attributes = new ReflectionClass(static::class)->getAttributes(SearchesModel::class);

        if ($attributes === []) {
            throw new InvalidSearchDefinition(sprintf('%s must declare its model with #[SearchesModel].', static::class));
        }

        return $attributes[0]->newInstance()->model;
    }
}
