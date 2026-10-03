<?php
declare(strict_types=1);

namespace Panth\LlmsTxt\Test\Unit\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Builds in-memory DB doubles: a fluent Select that records its calls and
 * an adapter answering table / column / row questions from arrays.
 */
trait DbStubTrait
{
    /** @var array<int,array{0:string,1:array}> */
    private array $selectCalls = [];

    /** @var array<int,string> spl_object_id(select) => first from() table */
    private array $selectTables = [];

    /** @var array<int,string[]> spl_object_id(select) => where() conditions */
    private array $selectWheres = [];

    private function fluentSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $id = spl_object_id($select);
        foreach (['from', 'where', 'group', 'order', 'limit', 'join', 'joinLeft', 'columns', 'reset'] as $method) {
            $select->method($method)->willReturnCallback(
                function (...$args) use ($method, $select, $id) {
                    $this->selectCalls[] = [$method, $args];
                    if ($method === 'from' && !isset($this->selectTables[$id])) {
                        $name = $args[0];
                        $this->selectTables[$id] = is_array($name) ? (string) reset($name) : (string) $name;
                    }
                    if ($method === 'where') {
                        $this->selectWheres[$id][] = (string) $args[0];
                    }
                    return $select;
                }
            );
        }
        return $select;
    }

    private function tableOf($select): string
    {
        return is_object($select) ? ($this->selectTables[spl_object_id($select)] ?? '') : '';
    }

    private function wheresOf($select): array
    {
        return is_object($select) ? ($this->selectWheres[spl_object_id($select)] ?? []) : [];
    }

    /**
     * @param string[]                    $tables  existing table names
     * @param array<string,array>         $columns table => describeTable() result
     * @param array<string,array>|callable $rows   table => fetchAll() rows, or callable(Select): array
     */
    private function connection(
        array $tables = [],
        array $columns = [],
        $rows = [],
        array $fetchCol = [],
        ?\Throwable $fetchAllError = null
    ): AdapterInterface {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            static fn (string $table): bool => in_array($table, $tables, true)
        );
        $connection->method('describeTable')->willReturnCallback(
            static fn (string $table): array => $columns[$table] ?? []
        );
        $connection->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->fluentSelect());
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace('?', implode(',', (array) $value), $text)
        );
        $connection->method('fetchAll')->willReturnCallback(
            function ($select = null) use ($rows, $fetchAllError): array {
                if ($fetchAllError !== null) {
                    throw $fetchAllError;
                }
                $table = $this->tableOf($select) ?: $this->lastFromTable();
                if (is_callable($rows)) {
                    return $rows($table, $this->wheresOf($select));
                }
                return $rows[$table] ?? [];
            }
        );
        $connection->method('fetchCol')->willReturnCallback(
            function ($select = null) use ($fetchCol): array {
                return $fetchCol[$this->tableOf($select) ?: $this->lastFromTable()] ?? [];
            }
        );
        return $connection;
    }

    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /**
     * Table name of the most recent from() call (alias arrays unwrapped).
     */
    private function lastFromTable(): string
    {
        for ($i = count($this->selectCalls) - 1; $i >= 0; $i--) {
            [$method, $args] = $this->selectCalls[$i];
            if ($method === 'from') {
                $name = $args[0];
                return is_array($name) ? (string) reset($name) : (string) $name;
            }
        }
        return '';
    }

    /**
     * @return string[] every where() condition recorded so far
     */
    private function whereConditions(): array
    {
        $out = [];
        foreach ($this->selectCalls as [$method, $args]) {
            if ($method === 'where') {
                $out[] = (string) $args[0];
            }
        }
        return $out;
    }
}
