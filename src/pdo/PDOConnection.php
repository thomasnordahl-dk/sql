<?php

namespace mindplay\sql\pdo;

use Exception;
use LogicException;
use mindplay\sql\framework\Connection;
use mindplay\sql\framework\Executable;
use mindplay\sql\framework\Result;
use mindplay\sql\framework\MappedExecutable;
use mindplay\sql\framework\SequencedExecutable;
use mindplay\sql\framework\Status;
use mindplay\sql\model\Column;
use PDO;
use PDOStatement;
use UnexpectedValueException;

/**
 * This class implements a Connection adapter for a PDO connection.
 */
class PDOConnection implements Connection
{
    /**
     * @var PDO
     */
    private $pdo;

    /**
     * @var int number of nested calls to transact()
     *
     * @see transact()
     */
    private $transaction_level = 0;

    /**
     * @var bool net result of nested calls to transact()
     *
     * @see transact()
     */
    private $transaction_result;

    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @inheritdoc
     */
    public function prepare(Executable $statement)
    {
        $params = $statement->getParams();

        $sql = $this->expandPlaceholders($statement->getSQL(), $params);
        
        $sequence_names = [];
        
        if ($statement instanceof SequencedExecutable) {
            foreach ($statement->getSequencedColumns() as $column) {
                $sequence_names[$column->getName()] = $column->getSequenceName();
            }
        }
        
        $prepared_statement = new PreparedPDOStatement($this->pdo, $this->pdo->prepare($sql), $sequence_names);
        
        foreach ($params as $name => $value) {
            if (is_array($value)) {
                $index = 1; // use a base-1 offset consistent with expandPlaceholders()

                foreach ($value as $item) {
                    // NOTE: we deliberately ignore the array indices here, as using them could result in broken SQL!

                    $prepared_statement->bind("{$name}_{$index}", $item);

                    $index += 1;
                }
            } else {
                $prepared_statement->bind($name, $value);
            }
        }

        return $prepared_statement;
    }

    /**
     * @inheritdoc
     */
    public function fetch(MappedExecutable $statement, $batch_size = 1000, array $mappers = [])
    {
        return new Result(
            $this->prepare($statement),
            $batch_size,
            array_merge($statement->getMappers(), $mappers)
        );
    }

    /**
     * @inheritdoc
     */
    public function execute(Executable $statement)
    {
        return $this->prepare($statement)->execute();
    }

    /**
     * @inheritdoc
     */
    public function transact(callable $func)
    {
        if ($this->transaction_level === 0) {
            // starting a new stack of transactions - assume success:
            $this->pdo->beginTransaction();
            $this->transaction_result = true;
        }

        $this->transaction_level += 1;

        /** @var mixed $commit return type of $func isn't guaranteed, therefore mixed rather than bool */

        try {
            $commit = call_user_func($func);
        } catch (Exception $exception) {
            $commit = false;
        }

        $this->transaction_result = ($commit === true) && $this->transaction_result;

        $this->transaction_level -= 1;

        if ($this->transaction_level === 0) {
            if ($this->transaction_result === true) {
                $this->pdo->commit();

                return true; // the net transaction is a success!
            } else {
                $this->pdo->rollBack();
            }
        }

        if (isset($exception)) {
            // re-throw unhandled Exception as a LogicException:
            throw new LogicException("unhandled Exception during transaction", 0, $exception);
        }

        if (! is_bool($commit)) {
            throw new UnexpectedValueException("\$func must return TRUE (to commit) or FALSE (to roll back)");
        }

        return $this->transaction_result;
    }

    /**
     * Internally expand SQL placeholders (for array-types)
     *
     * @param string $sql    SQL with placeholders
     * @param array  $params placeholder name/value pairs
     *
     * @return string SQL with expanded placeholders
     */
    private function expandPlaceholders($sql, array $params)
    {
        $replace_pairs = [];

        foreach ($params as $name => $value) {
            if (is_array($value)) {
                // TODO: QA! For empty arrays, the resulting SQL is e.g.: "SELECT * FROM foo WHERE foo.bar IN (null)"

                $replace_pairs[":{$name}"] = count($value) === 0
                    ? "(null)" // empty set
                    : "(" . implode(', ', array_map(function ($i) use ($name) {
                        return ":{$name}_{$i}";
                    }, range(1, count($value)))) . ")";
            }
        }

        return count($replace_pairs)
            ? strtr($sql, $replace_pairs)
            : $sql; // no arrays found in the given parameters
    }
}
