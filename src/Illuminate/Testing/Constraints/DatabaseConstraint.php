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
     * The builder instance with query constraints.
     */
    protected QueryBuilder $builder;

    /**
     * The number of results to show on a failed match.
     */
    protected int $show = 3;

    /**
     * A callback to determine if a match exists.
     */
    protected Closure $matchCallback;

    /**
     * A callback to build the failure message.
     */
    protected Closure $failureDescriptionCallback;

    /**
     * The conditions to add when debugging a failure.
     */
    protected Closure|null $failureConditionalCallback = null;

    /**
     * The actual table entries count that will be checked against the expected count.
     */
    protected int $actualCount = 0;

    /**
     * Determine if we should show the additional failure description.
     */
    protected bool $showAdditionalFailureDescription = false;

    /**
     * Create a new constraint instance.
     */
    public function __construct(
        EloquentBuilder|QueryBuilder $builder,
    ) {
        $this->builder = $builder instanceof EloquentBuilder
            ? $builder->toBase()
            : $builder;
    }

    /**
     * Set the number of records that will be shown in the console in case of failure.
     */
    public function show(int $count): static
    {
        $this->show = $count;

        return $this;
    }

    /**
     * Set the conditions to add when debugging a failure.
     */
    public function debug(Closure|null $callback): static
    {
        $this->failureConditionalCallback = $callback;

        return $this;
    }

    /**
     * Execute the constraint checking if the builder exists.
     */
    public function exists(): void
    {
        $this->matchCallback = fn () => $this->builder->exists();

        $this->failureDescriptionCallback = function ($table) {
            return sprintf(
                'a row in the table [%s] matches the query: ' . PHP_EOL . PHP_EOL . ' %s',
                $table,
                $this->toString(),
            );
        };

        $this->showAdditionalFailureDescription = true;

        $this->runAssertion();
    }

    /**
     * Execute the constraint checking if the builder does not exist.
     */
    public function missing(): void
    {
        $this->matchCallback = fn () => $this->builder->doesntExist();

        $this->failureDescriptionCallback = function ($table) {
            return sprintf(
                'a row in the table [%s] does not match the query: ' . PHP_EOL . PHP_EOL . ' %s',
                $table,
                $this->toString(),
            );
        };

        $this->showAdditionalFailureDescription = true;

        $this->runAssertion();
    }

    /**
     * Execute the constraint checking if the builder matches the count.
     */
    public function countIs(int $count, string $comparator = '=='): void
    {
        $this->matchCallback = function () use ($count, $comparator) {

            $this->actualCount = $result = $this->builder->count();

            return match ($comparator) {
                '>'         => $result > $count,
                '>='        => $result >= $count,
                '<'         => $result < $count,
                '<='        => $result <= $count,
                '!=', '!==' => $result !== $count,
                default     => $result === $count,
            };
        };

        $this->failureDescriptionCallback = function ($table) use ($count, $comparator) {
            return sprintf(
                'table [%s] actual count of %s is %s expected count of %s' . PHP_EOL . PHP_EOL . ' %s',
                $table,
                $this->actualCount,
                $comparator,
                $count,
                $this->toString(),
            );
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
    protected function matches(mixed $other): bool
    {
        return ($this->matchCallback)();
    }

    /**
     * Run the assertion for this constraint.
     */
    protected function runAssertion(): void
    {
        Assert::assertThat(
            $this->builder->from,
            $this,
        );
    }

    /**
     * Get the description of the failure.
     */
    protected function failureDescription(mixed $other): string
    {
        return ($this->failureDescriptionCallback)($other);
    }

    /**
     * Get additional info about the records found in the database table.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        if (!$this->showAdditionalFailureDescription) {
            return '';
        }

        $baseBuilder = clone $this->builder;
        $allWheres = $baseBuilder->wheres;
        $baseBuilder->wheres = [];
        $baseBuilder->bindings['where'] = [];
        $baseBuilder->joins = null;
        $baseBuilder->groups = null;
        $baseBuilder->havings = null;
        $baseBuilder->bindings['join'] = [];
        $baseBuilder->bindings['having'] = [];

        // the table is empty
        if ($baseBuilder->count() === 0) {
            return PHP_EOL . 'The table [' . $other . '] is empty.';
        }

        // show table results
        else {

            // add debug conditionals
            if ($this->failureConditionalCallback instanceof Closure) {
                $baseBuilder = ($this->failureConditionalCallback)($baseBuilder);
            }

            $results = $baseBuilder
                ->select(array_filter(array_map(fn ($item) => $item['column'] ?? null, $allWheres)))
                ->cursor();

            $resultsCount = $results->count();

            return PHP_EOL . 'Showing ' . min($this->show, $resultsCount) . ' of ' . $resultsCount . ' results: ' . PHP_EOL . json_encode($results->take($this->show), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

        $replace = array_map(fn ($item) => PHP_EOL . $item, $search);

        return str_replace($search, $replace, $this->builder->toRawSql());
    }
}
