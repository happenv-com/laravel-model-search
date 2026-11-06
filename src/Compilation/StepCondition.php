<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Compilation;

use BackedEnum;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

/**
 * A restriction a field puts on the rows of one relation step of its path: `column = value`, or
 * `column IN (values)`. The column belongs to the table of the step; the relation names the step by
 * the dotted prefix of the field path that leads to it.
 *
 * @internal
 */
final readonly class StepCondition
{
    /**
     * @param  list<bool|float|int|string>  $values  At least one; one value means equality, several mean "any of". Once
     *                                               compiled they are the text form of the value in the column's type.
     */
    private function __construct(
        public string $relation,
        public string $column,
        public array $values,
    ) {}

    /**
     * @param  mixed  $value  A scalar, a backed enum, or a non-empty list of them; anything else is refused.
     */
    public static function of(string $relation, string $column, mixed $value): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $relation) !== 1) {
            throw new InvalidSearchDefinition(sprintf('"%s" is not a relation of a field path; name the relation segment the condition applies to, for example "documents" or "order.documents".', $relation));
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new InvalidSearchDefinition(sprintf('"%s" is not a column name; a step condition names one column of the table of the relation "%s".', $column, $relation));
        }

        $list = is_array($value) ? $value : [$value];

        if ($list === [] || ! array_is_list($list)) {
            throw new InvalidSearchDefinition(sprintf('The condition on "%s.%s" needs a value, or a non-empty list of values.', $relation, $column));
        }

        $values = [];

        foreach ($list as $item) {
            $item = $item instanceof BackedEnum ? $item->value : $item;

            if (! is_scalar($item)) {
                throw new InvalidSearchDefinition(sprintf('The condition on "%s.%s" accepts scalars and backed enums (or a list of them), nothing else.', $relation, $column));
            }

            if (is_float($item) && ! is_finite($item)) {
                throw new InvalidSearchDefinition(sprintf('The condition on "%s.%s" has a value that is not a finite number.', $relation, $column));
            }

            $values[] = $item;
        }

        return new self($relation, $column, $values);
    }

    /**
     * The same condition with its values replaced by their text form in the type of the column, deduplicated and
     * sorted, so two spellings of one condition are one condition.
     *
     * @param  list<string>  $texts
     */
    public function withTexts(array $texts): self
    {
        $texts = array_values(array_unique($texts));
        sort($texts);

        return new self($this->relation, $this->column, $texts);
    }

    /**
     * @return array{relation: string, column: string, values: list<bool|float|int|string>}
     */
    public function toArray(): array
    {
        return ['relation' => $this->relation, 'column' => $this->column, 'values' => $this->values];
    }
}
