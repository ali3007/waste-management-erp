<?php
/**
 * Thin PDO wrapper. Every query in the system is prepared — the helpers
 * below deliberately offer no way to concatenate user input into SQL.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class Database
{
    private static ?PDO $pdo = null;

    private function __construct() {}

    /** Shared connection, opened lazily on first use. */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!IS_INSTALLED) {
            throw new RuntimeException('The application is not installed yet.');
        }

        self::$pdo = self::connect(DB_HOST, (string)DB_PORT, DB_NAME, DB_USER, DB_PASS);
        return self::$pdo;
    }

    /**
     * Open a connection with the given credentials.
     * Also used by the installer to test credentials before writing config.
     */
    public static function connect(
        string $host,
        string $port,
        string $database,
        string $user,
        string $password,
        bool $selectDatabase = true
    ): PDO {
        $dsn = 'mysql:host=' . $host . ';port=' . ($port !== '' ? $port : '3306');
        if ($selectDatabase && $database !== '') {
            $dsn .= ';dbname=' . $database;
        }
        $dsn .= ';charset=' . DB_CHARSET;

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }

    /** Allow the installer to reuse the connection it just validated. */
    public static function useConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** Single row, or null. */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** First column of the first row, or null. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function exists(string $sql, array $params = []): bool
    {
        return self::fetch($sql, $params) !== null;
    }

    /** Run a statement and return the number of affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Insert an associative array into a table and return the new id. */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ')';
        self::query($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    /** Update rows matching a simple equality condition set. */
    public static function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = '`' . $column . '` = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . $column . '` = :where_' . $column;
            $params['where_' . $column] = $value;
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions);
        return self::execute($sql, $params);
    }

    public static function beginTransaction(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollBack(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /** True when the connection is usable — used by health checks. */
    public static function isHealthy(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            log_event('database', 'Health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Turn a driver exception into a message that is safe to show a user.
     * The technical detail goes to the log, never to the browser.
     */
    public static function friendlyError(Throwable $e): string
    {
        log_event('database', 'Query failed', ['error' => $e->getMessage()]);
        $code = $e instanceof PDOException ? (string)$e->getCode() : '';

        return match ($code) {
            '1045' => 'The database username or password was rejected.',
            '1049' => 'That database does not exist on the server.',
            '2002' => 'The database server could not be reached at that host or port.',
            '1044' => 'This user does not have access to that database.',
            default => APP_ENV === 'development'
                ? 'Database error: ' . $e->getMessage()
                : 'A database error occurred. Check the log for details.',
        };
    }
}
