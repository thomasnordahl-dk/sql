<?php

namespace mindplay\sql\framework;

use InvalidArgumentException;
use LogicException;
use mindplay\sql\model\Column;
use UnexpectedValueException;

/**
 * This interface defines the responsibilities of the Connection model.
 */
interface Connection
{
    /**
     * Fetch the `Result` of executing an SQL "SELECT" statement.
     *
     * Note that you can directly iterate over the `Result` instance.
     *
     * @param MappedExecutable $statement
     * @param int              $batch_size batch-size (when fetching large result sets)
     * @param Mapper[]         $mappers    list of additional Mappers to apply while fetching results
     *
     * @return Result
     */
    public function fetch(MappedExecutable $statement, $batch_size = 1000, array $mappers = []);

    /**
     * Execute an SQL statement, which does not produce a result, e.g. an "INSERT", "UPDATE" or "DELETE" statement.
     *
     * @param Executable $statement
     *
     * @return Status
     */
    public function execute(Executable $statement);

    /**
     * Prepare an SQL statement.
     *
     * @param Executable $statement
     *
     * @return PreparedStatement
     */
    public function prepare(Executable $statement);

    /**
     * @param callable $func function () : bool - must return TRUE to commit or FALSE to roll back
     *
     * @return bool TRUE on success (committed) or FALSE on failure (rolled back)
     *
     * @throws InvalidArgumentException if the provided argument is not a callable function
     * @throws LogicException if an unhandled Exception occurs while calling the provided function
     * @throws UnexpectedValueException if the provided function does not return TRUE or FALSE
     */
    public function transact(callable $func);
}
