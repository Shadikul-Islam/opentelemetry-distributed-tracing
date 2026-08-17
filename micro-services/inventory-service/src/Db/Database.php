<?php

namespace Inventory\Db;

use Inventory\Tracing\Span;
use Inventory\Tracing\Tracer;

final class Database
{
    private $tracer;

    private $pdo;

    private $host;

    private $port;

    private $database;

    private $user;

    private $password;

    public function __construct(Tracer $tracer, $host, $port, $database, $user, $password)
    {
        $this->tracer = $tracer;
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
    }

    private function pdo()
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $span = $this->tracer->startSpan('connect ' . $this->database, Span::KIND_CLIENT, $this->baseAttributes());

        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->host, $this->port, $this->database);

            $this->pdo = new \PDO($dsn, $this->user, $this->password, array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ));
        } catch (\Throwable $e) {
            $this->fail($span, $e);
            throw $e;
        } finally {
            $this->tracer->endSpan($span);
        }

        return $this->pdo;
    }

    public function select($sql, array $params, $operation, $table)
    {
        return $this->run($sql, $params, $operation, $table, function (\PDOStatement $statement) {
            return $statement->fetchAll();
        });
    }

    public function selectOne($sql, array $params, $operation, $table)
    {
        $rows = $this->select($sql, $params, $operation, $table);
        return empty($rows) ? null : $rows[0];
    }

    public function execute($sql, array $params, $operation, $table)
    {
        return $this->run($sql, $params, $operation, $table, function (\PDOStatement $statement) {
            return $statement->rowCount();
        });
    }

    public function transaction(callable $work)
    {
        $pdo = $this->pdo();
        $span = $this->tracer->startSpan('transaction ' . $this->database, Span::KIND_CLIENT, $this->baseAttributes());

        $pdo->beginTransaction();
        try {
            $result = $work($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail($span, $e);
            throw $e;
        } finally {
            $this->tracer->endSpan($span);
        }
    }

    private function run($sql, array $params, $operation, $table, callable $extract)
    {
        $pdo = $this->pdo();

        $span = $this->tracer->startSpan(
            $operation . ' ' . $this->database . '.' . $table,
            Span::KIND_CLIENT,
            array_merge($this->baseAttributes(), array(
                'db.statement' => $this->collapse($sql),
                'db.operation' => $operation,
                'db.sql.table' => $table,
            ))
        );

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $result = $extract($statement);

            if (is_int($result)) {
                $span->setAttribute('db.rows_affected', $result);
            } elseif (is_array($result)) {
                $span->setAttribute('db.rows_returned', count($result));
            }

            return $result;
        } catch (\Throwable $e) {
            $this->fail($span, $e);
            throw $e;
        } finally {
            $this->tracer->endSpan($span);
        }
    }

    private function baseAttributes()
    {
        return array(
            'db.system' => 'mysql',
            'db.name' => $this->database,
            'db.user' => $this->user,
            'server.address' => $this->host,
            'server.port' => $this->port,
        );
    }

    private function fail(Span $span, \Throwable $e)
    {
        $span->setStatus(Span::STATUS_ERROR, $e->getMessage());
        $span->recordException($e, Tracer::nowNanos());
    }

    private function collapse($sql)
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }
}
