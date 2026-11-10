<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

/**
 * The column type of `node_id` in the change log (`model-search.node_key_type`, `text` by default).
 *
 * A node is a row of any watched table, written by the triggers as the key it points the change at: a child's foreign
 * key to its parent, or a root's own key. `text` holds them all, also when the watched tables key in different types.
 * An application whose watched tables all key in one type can name it; then it must be the subject key type as well,
 * because the drain writes roots back to the log as nodes.
 *
 * @internal
 */
final class NodeKey extends ConfiguredKeyType
{
    public static function setting(): string
    {
        return 'node_key_type';
    }

    protected static function defaultType(): string
    {
        return 'text';
    }

    /**
     * @throws InvalidSearchDefinition when a typed node key differs from the subject key type
     */
    public static function assertConsistentWithSubjects(): void
    {
        if (self::type() !== 'text' && self::type() !== SubjectKey::type()) {
            throw new InvalidSearchDefinition(sprintf(
                'model-search.node_key_type is %s, but model-search.subject_key_type is %s: a typed node key must be the subject key type (the drain writes roots back to the change log as nodes), or text.',
                self::type(),
                SubjectKey::type(),
            ));
        }
    }
}
