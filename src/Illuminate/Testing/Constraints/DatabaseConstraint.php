<?php

namespace Illuminate\Testing\Constraints;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Testing\Assert;
use PHPUnit\Framework\Constraint\Constraint;

class DatabaseConstraint extends Constraint
{
    /**
     * A callback to determine if a match exists.
     */
    protected Closure $matchCallback;

    /**
     * The number of results to show on a failed match.
     */
    protected int $show = 3;

    /**
     * The builder instance with query constraints.
     */
    protected QueryBuilder $builder;

    /**
     * Create a new constraint instance.
     */
    public function __construct(
        EloquentBuilder|QueryBuilder $builder,
    ) {
        if ($builder instanceof EloquentBuilder) {
            $this->builder = $builder->toBase();
        } else {
            $this->builder = $builder;
        }
    }

    /**
     * Set the number of records that will be shown in the console in case of failure.
     */
    public function shown(int $count): static
    {
        $this->show = $count;

        return $this;
    }

    /**
     * Run the assertion for this constraint.
     */
    public function runAssertion(): void
    {
        Assert::assertThat(
            $this->builder->from,
            $this,
        );
    }

    /**
     * Execute the constraint checking if the builder exists.
     */
    public function exists(): void
    {
        $this->matchCallback = fn () => $this->builder->exists();

        $this->runAssertion();
    }

    /**
     * Execute the constraint checking if the builder does not exist.
     */
    public function missing(): void
    {
        $this->matchCallback = fn () => $this->builder->doesntExist();

        $this->runAssertion();
    }

    /**
     * Execute the constraint checking if the builder matches the count.
     */
    public function countIs(int $count, string $comparator = '=='): void
    {
        $this->matchCallback = function () use ($count, $comparator) {

            $result = $this->builder->count();

            return match ($comparator) {
                '>'         => $result > $count,
                '>='        => $result >= $count,
                '<'         => $result < $count,
                '<='        => $result <= $count,
                '!=', '!==' => $result !== $count,
                default     => $result === $count,
            };
        };

        $this->runAssertion();
    }

    /**
     * Execute the constraint checking if the builder is empty.
     */
    public function empty(): void
    {
        $this->countIs(0);
    }

    /**
     * Check if the data is found in the given table.
     */
    public function matches(mixed $other): bool
    {
        return ($this->matchCallback)();
    }

    /**
     * Get the description of the failure.
     */
    public function failureDescription(mixed $other): string
    {
        return sprintf(
            "a row in the table [%s] matches the query: " . PHP_EOL . PHP_EOL . " %s",
            $other,
            $this->toString(),
        );
    }

    /**
     * Get additional info about the records found in the database table.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        $baseBuilder = clone $this->builder;
        $allWheres = $baseBuilder->wheres;
        $baseBuilder->wheres = [];
        $baseBuilder->bindings['where'] = [];
        $baseBuilder->joins = null;
        $baseBuilder->groups = null;
        $baseBuilder->havings = null;
        $baseBuilder->bindings['join'] = [];
        $baseBuilder->bindings['having'] = [];

        if ($baseBuilder->count() === 0) {
            return PHP_EOL . 'The table [' . $other . '] is empty.';
        }

        // look for similar results to the given query based on the first where condition
        else {

            $similarResults = $baseBuilder
                ->select(array_filter(array_map(fn ($item) => $item['column'] ?? null, $allWheres)))
                ->cursor();

            $similarResultsCount = $similarResults->count();

            return PHP_EOL . 'Found ' . $similarResultsCount . ' similar results: ' . PHP_EOL . json_encode($similarResults->take($this->show), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get a string representation of the object.
     */
    public function toString(): string
    {
        $search = [
            ' from ',
            ' where ',
            ' and ',
            ' or ',
            ' having ',
        ];

        $replace = array_map(fn($item) => PHP_EOL . $item, $search);

        return str_replace($search, $replace, $this->builder->toRawSql());
    }
}
