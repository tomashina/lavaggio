<?php

declare(strict_types=1);

/**
 * One-time, loss-checked Lavaggio SQLite -> MySQL/MariaDB transfer.
 *
 * php scripts/migrate-lavaggio-sqlite-to-mysql.php --credentials=/private/mysql.json
 * php scripts/migrate-lavaggio-sqlite-to-mysql.php --credentials=/private/mysql.json --apply
 * php scripts/migrate-lavaggio-sqlite-to-mysql.php --credentials=/private/mysql.json --verify
 *
 * The owner-only JSON file contains host, port, database, username, password and
 * optional unix_socket. The live target is fixed to baredahr_lavaggioshop. This
 * script never changes .env, touches the old main-site database, sends messages,
 * imports legacy catalogues, or modifies the source SQLite file. An interrupted
 * transfer leaves its new target for inspection; it never drops a database.
 */

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const LIVE_TARGET = 'baredahr_lavaggioshop';

$phase = 'argument validation';
$target = null;
$source = null;
$lock = null;
$inTransaction = false;

function stopTransfer(string $message): never
{
    throw new RuntimeException($message);
}

function quoteMysql(string $identifier): string
{
    if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier)) {
        stopTransfer('Unsupported SQL identifier; no transfer was attempted.');
    }

    return '`'.$identifier.'`';
}

function quoteSqlite(string $identifier): string
{
    quoteMysql($identifier);

    return '"'.$identifier.'"';
}

function tableNames(PDO $connection, bool $sqlite): array
{
    $sql = $sqlite
        ? "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME';

    $names = $connection->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    // SQLite binary ordering and MySQL's information_schema collation differ.
    sort($names, SORT_STRING);

    return $names;
}

function normalizeNumber(string $value): string
{
    if (strpbrk($value, 'eE') !== false) {
        $value = rtrim(rtrim(sprintf('%.14F', (float) $value), '0'), '.');
    }
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '+-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = rtrim($fraction, '0');
    $value = $whole.($fraction === '' ? '' : '.'.$fraction);

    return ($negative && $value !== '0' ? '-' : '').$value;
}

function canonicalJson(mixed $value): mixed
{
    if (is_object($value)) {
        $values = get_object_vars($value);
        ksort($values, SORT_STRING);
        foreach ($values as &$item) {
            $item = canonicalJson($item);
        }

        return ['object', $values];
    }
    if (is_array($value)) {
        return ['array', array_map(canonicalJson(...), $value)];
    }
    if (is_int($value) || is_float($value)) {
        // Distinguish JSON strings from numbers while ignoring JSON reformatting.
        return ['number', normalizeNumber((string) $value)];
    }

    return [get_debug_type($value), $value];
}

function canonicalCell(mixed $value, array $column): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }
    $value = (string) $value;
    $type = strtolower($column['DATA_TYPE']);
    if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit'], true)) {
        return ['number' => normalizeNumber($value)];
    }
    if ($type === 'json') {
        return ['json' => canonicalJson(json_decode($value, flags: JSON_THROW_ON_ERROR))];
    }
    if (in_array($type, ['datetime', 'timestamp', 'time'], true)) {
        $value = preg_replace('/\.0+$/', '', $value);
    }
    if ($type === 'date' && preg_match('/\A(\d{4}-\d{2}-\d{2}) 00:00:00(?:\.0+)?\z/', $value, $date)) {
        // Laravel serializes SQLite date casts at midnight; MySQL DATE returns
        // the same calendar date without that redundant midnight component.
        $value = $date[1];
    }

    return ['bytes' => base64_encode($value)];
}

function tableDigest(PDO $connection, string $table, array $columns, bool $sqlite): array
{
    $names = array_keys($columns);
    $quote = $sqlite ? quoteSqlite(...) : quoteMysql(...);
    $statement = $connection->query('SELECT '.implode(',', array_map($quote, $names)).' FROM '.$quote($table));
    $rowHashes = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $canonical = [];
        foreach ($names as $name) {
            $canonical[$name] = canonicalCell($row[$name], $columns[$name]);
        }
        $rowHashes[] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
    sort($rowHashes, SORT_STRING);

    return ['count' => count($rowHashes), 'sha256' => hash('sha256', implode("\n", $rowHashes))];
}

function foreignKeyCheck(PDO $target): void
{
    $rows = $target->query("SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION
        FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $constraints = [];
    foreach ($rows as $row) {
        $constraints[$row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME']][] = $row;
    }
    foreach ($constraints as $parts) {
        $joins = [];
        $notNull = [];
        foreach ($parts as $part) {
            $joins[] = 'c.'.quoteMysql($part['COLUMN_NAME']).'=p.'.quoteMysql($part['REFERENCED_COLUMN_NAME']);
            $notNull[] = 'c.'.quoteMysql($part['COLUMN_NAME']).' IS NOT NULL';
        }
        $first = $parts[0];
        $sql = 'SELECT COUNT(*) FROM '.quoteMysql($first['TABLE_NAME']).' c LEFT JOIN '.quoteMysql($first['REFERENCED_TABLE_NAME']).' p ON '.implode(' AND ', $joins)
            .' WHERE '.implode(' AND ', $notNull).' AND p.'.quoteMysql($first['REFERENCED_COLUMN_NAME']).' IS NULL';
        if ((int) $target->query($sql)->fetchColumn() !== 0) {
            stopTransfer('Foreign-key verification failed for table '.$first['TABLE_NAME'].'.');
        }
    }
}

function widenIntegerColumns(PDO $source, PDO $target, array &$schema): void
{
    // SQLite does not enforce Laravel's tiny/small/int ranges. Preserve imported
    // values by widening only a new target column that demonstrably needs it.
    $bounds = [
        'tinyint' => [-128, 127, 255], 'smallint' => [-32768, 32767, 65535],
        'mediumint' => [-8388608, 8388607, 16777215],
        'int' => [-2147483648, 2147483647, 4294967295],
        'bigint' => [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX],
    ];
    foreach ($schema as $table => &$columns) {
        foreach ($columns as $name => &$column) {
            $type = strtolower($column['DATA_TYPE']);
            if (! isset($bounds[$type])) {
                continue;
            }
            $range = $source->query('SELECT MIN('.quoteSqlite($name).'),MAX('.quoteSqlite($name).') FROM '.quoteSqlite($table))->fetch(PDO::FETCH_NUM);
            if ($range[0] === null) {
                continue;
            }
            if (! preg_match('/\A-?\d+\z/', (string) $range[0]) || ! preg_match('/\A-?\d+\z/', (string) $range[1])) {
                stopTransfer('A source integer column contains non-integer values: '.$table.'.'.$name.'.');
            }
            $unsigned = str_contains(strtolower($column['COLUMN_TYPE']), 'unsigned');
            $lower = $unsigned ? 0 : $bounds[$type][0];
            $upper = $bounds[$type][$unsigned ? 2 : 1];
            if ((int) $range[0] >= $lower && (int) $range[1] <= $upper) {
                continue;
            }
            $references = $target->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND '
                .'((TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=?))');
            $references->execute([$table, $name, $table, $name]);
            if ((int) $references->fetchColumn() !== 0 || str_contains($column['EXTRA'], 'auto_increment')) {
                stopTransfer('An identity or foreign-key integer needs separate review: '.$table.'.'.$name.'.');
            }
            $definition = 'BIGINT'.((int) $range[0] >= 0 ? ' UNSIGNED' : '');
            $definition .= $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
            if ($column['COLUMN_DEFAULT'] !== null) {
                $default = trim((string) $column['COLUMN_DEFAULT'], "'");
                if (strtolower($default) === 'null' && $column['IS_NULLABLE'] === 'YES') {
                    $definition .= ' DEFAULT NULL';
                } elseif (preg_match('/\A-?\d+\z/', $default)) {
                    $definition .= ' DEFAULT '.$default;
                } else {
                    stopTransfer('Unsupported integer default at '.$table.'.'.$name.'.');
                }
            }
            $target->exec('ALTER TABLE '.quoteMysql($table).' MODIFY COLUMN '.quoteMysql($name).' '.$definition);
            $column['DATA_TYPE'] = 'bigint';
            $column['COLUMN_TYPE'] = strtolower($definition);
            echo 'Preserved wider SQLite integer range: '.$table.'.'.$name.' -> BIGINT'.PHP_EOL;
        }
    }
}

function preserveSequenceFloors(PDO $source, PDO $target, array $schema, bool $apply): void
{
    $next = [];
    foreach ($target->query('SHOW TABLE STATUS', PDO::FETCH_ASSOC) as $status) {
        $next[$status['Name']] = (int) ($status['Auto_increment'] ?? 0);
    }
    foreach ($source->query('SELECT name,seq FROM sqlite_sequence', PDO::FETCH_ASSOC) as $sequence) {
        $table = $sequence['name'];
        if (! isset($schema[$table]) || ! array_filter($schema[$table], fn (array $column): bool => str_contains($column['EXTRA'], 'auto_increment'))) {
            continue;
        }
        if ((int) $sequence['seq'] === PHP_INT_MAX) {
            stopTransfer('An exhausted source auto-increment needs separate review: '.$table.'.');
        }
        $floor = (int) $sequence['seq'] + 1;
        if (($next[$table] ?? 0) >= $floor) {
            continue;
        }
        if (! $apply) {
            stopTransfer('The target auto-increment could reuse a historical source ID: '.$table.'.');
        }
        $target->exec('ALTER TABLE '.quoteMysql($table).' AUTO_INCREMENT='.$floor);
        echo 'Preserved historical auto-increment floor: '.$table.PHP_EOL;
    }
}

try {
    if (PHP_SAPI !== 'cli') {
        stopTransfer('This utility is CLI-only.');
    }
    $options = getopt('', ['credentials:', 'source:', 'apply', 'verify', 'rehearsal', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php scripts/migrate-lavaggio-sqlite-to-mysql.php --credentials=/private/mysql.json [--source=/private/snapshot.sqlite] [--apply|--verify]\n";
        exit(0);
    }
    if (isset($options['apply'], $options['verify'])) {
        stopTransfer('Choose either --apply or --verify.');
    }
    $apply = isset($options['apply']);
    $verifyOnly = isset($options['verify']);
    $root = dirname(__DIR__);
    $credentialsFile = $options['credentials'] ?? '';
    if (! is_string($credentialsFile) || ! is_file($credentialsFile) || is_link($credentialsFile) || (fileperms($credentialsFile) & 0077) !== 0) {
        stopTransfer('Credentials must be in a regular owner-only JSON file (chmod 600).');
    }
    $credentials = json_decode(file_get_contents($credentialsFile), true, flags: JSON_THROW_ON_ERROR);
    foreach (['database', 'username', 'password'] as $key) {
        if (! isset($credentials[$key]) || ! is_string($credentials[$key]) || ($key !== 'password' && $credentials[$key] === '')) {
            stopTransfer('Missing credential field: '.$key.'.');
        }
    }
    $database = $credentials['database'];
    $host = $credentials['host'] ?? 'localhost';
    $port = (int) ($credentials['port'] ?? 3306);
    $socket = $credentials['unix_socket'] ?? '';
    $rehearsal = isset($options['rehearsal']);
    if (($rehearsal && ! preg_match('/\Alavaggio_transfer_test_[a-f0-9]{8,16}\z/', $database)) || (! $rehearsal && $database !== LIVE_TARGET)) {
        stopTransfer('Only the separate '.LIVE_TARGET.' database is allowed. The main-site database is forbidden.');
    }
    if (! $rehearsal && ($credentials['username'] !== LIVE_TARGET || $port !== 3306 || $socket !== '')) {
        stopTransfer('The live target requires its dedicated baredahr_lavaggioshop user on local port 3306.');
    }
    if (! in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $port < 1 || $port > 65535 || preg_match('/[;\r\n]/', $database.$host.$socket)) {
        stopTransfer('Only an explicitly local database connection is supported.');
    }
    if (! $rehearsal && $apply && ($root !== '/home/baredahr/shop.lavaggio.hr' || ! is_file($root.'/storage/framework/down'))) {
        stopTransfer('For the live transfer, run from the standard server project while Laravel is in maintenance mode.');
    }
    $sourceFile = $options['source'] ?? $root.'/database/database.sqlite';
    if (! is_string($sourceFile) || ! is_file($sourceFile) || is_link($sourceFile) || realpath($sourceFile) !== $sourceFile) {
        stopTransfer('The source must be an absolute ordinary SQLite file.');
    }
    $source = new PDO('sqlite:file:'.$sourceFile.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $source->exec('PRAGMA query_only=ON');
    $source->beginTransaction();
    if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $source->query('PRAGMA foreign_key_check')->fetch() !== false) {
        stopTransfer('SQLite integrity or foreign-key validation failed.');
    }
    $tables = tableNames($source, true);
    if (! in_array('migrations', $tables, true) || ! in_array('products', $tables, true) || ! in_array('system_settings', $tables, true)) {
        stopTransfer('The source is not the expected Lavaggio application database.');
    }
    $ran = $source->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $expected = array_map(fn (string $file): string => basename($file, '.php'), glob($root.'/database/migrations/*.php'));
    sort($ran);
    sort($expected);
    if ($ran !== $expected) {
        stopTransfer('Source migrations and the current checkout differ; review before transferring.');
    }

    $phase = 'target connection';
    $dsn = $socket !== '' ? 'mysql:unix_socket='.$socket : 'mysql:host='.$host.';port='.$port;
    $target = new PDO($dsn.';dbname='.$database.';charset=utf8mb4', $credentials['username'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($target->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        stopTransfer('The connected database does not match the fixed target.');
    }
    $existingObjects = (int) $target->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if (! $verifyOnly && $existingObjects !== 0) {
        stopTransfer('The target must be completely empty. Existing data was not changed.');
    }
    echo 'Source: '.count($tables).' tables; target: '.$database.'. Source is read-only.'.PHP_EOL;
    if (! $apply && ! $verifyOnly) {
        echo "Plan: create schema with the current Laravel migrations, replace their bootstrap rows with every source row, verify all row digests and foreign keys.\n";
        echo "Read-only preflight passed. Use --apply to transfer; .env stays unchanged until separately switched after verification.\n";
        exit(0);
    }

    if ($apply) {
        $phase = 'transfer lock';
        $lockPath = $root.'/storage/app/private/.lavaggio-mysql-transfer.lock';
        if (is_link($lockPath)) {
            stopTransfer('Unexpected symlink at the transfer lock.');
        }
        $lock = fopen($lockPath, 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            stopTransfer('Another database transfer is already running.');
        }
        chmod($lockPath, 0600);
        $phase = 'isolated Laravel schema creation';
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $mysql = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
            'username' => $credentials['username'], 'password' => $credentials['password'], 'unix_socket' => $socket,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB'];
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($mysql): void {
            $app['config']->set([
                'database.default' => 'mysql', 'database.connections' => ['mysql' => $mysql, 'mariadb' => $mysql],
                'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
                'commerce.local_safe_mode' => true, 'activitylog.enabled' => false,
                'activitylog.database_connection' => 'mysql', 'pennant.stores.database.connection' => 'mysql',
            ]);
        });
        $app->afterBootstrapping(RegisterProviders::class, function (): void {
            Http::preventStrayRequests();
            Mail::fake();
            Bus::fake();
            Queue::fake();
        });
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        // Recheck after application boot, before the first DDL statement.
        if ((int) $target->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
            stopTransfer('The empty target changed during preflight; stopped before schema creation.');
        }
        if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]) !== 0) {
            stopTransfer('Laravel schema creation did not finish. Inspect the new target before retrying.');
        }
        echo "Laravel migrations completed in the new target only.\n";
    }

    $phase = 'schema comparison';
    $targetTables = tableNames($target, false);
    if ($targetTables !== $tables) {
        stopTransfer('Source and target table sets differ; no application connection was changed.');
    }
    $schema = [];
    foreach ($tables as $table) {
        $statement = $target->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$table]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $schema[$table][$column['COLUMN_NAME']] = $column;
        }
        $sourceColumns = array_column($source->query('PRAGMA table_info('.quoteSqlite($table).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $targetColumns = array_keys($schema[$table]);
        sort($sourceColumns);
        sort($targetColumns);
        if ($sourceColumns !== $targetColumns) {
            stopTransfer('Source and target column sets differ for '.$table.'.');
        }
    }

    if ($apply) {
        $phase = 'integer range compatibility';
        widenIntegerColumns($source, $target, $schema);
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
        $target->beginTransaction();
        $inTransaction = true;
        foreach ($tables as $table) {
            $phase = 'copying table '.$table;
            $target->exec('DELETE FROM '.quoteMysql($table));
            $columns = array_keys($schema[$table]);
            $select = $source->query('SELECT '.implode(',', array_map(quoteSqlite(...), $columns)).' FROM '.quoteSqlite($table));
            $insert = $target->prepare('INSERT INTO '.quoteMysql($table).' ('.implode(',', array_map(quoteMysql(...), $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
            while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) {
                $insert->execute($row);
            }
        }
    }

    $verified = [];
    foreach ($tables as $table) {
        $phase = 'verifying table '.$table;
        $sourceDigest = tableDigest($source, $table, $schema[$table], true);
        $targetDigest = tableDigest($target, $table, $schema[$table], false);
        if ($sourceDigest !== $targetDigest) {
            stopTransfer('Row count or content verification failed for '.$table.'.');
        }
        $verified[$table] = $targetDigest;
    }
    $phase = 'foreign-key verification';
    foreignKeyCheck($target);
    if ($inTransaction) {
        $target->commit();
        $inTransaction = false;
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS=1');
    }
    $phase = 'historical identity verification';
    preserveSequenceFloors($source, $target, $schema, $apply);
    echo 'Verified '.count($verified).' tables and '.array_sum(array_column($verified, 'count')).' rows; all canonical row digests and foreign keys match.'.PHP_EOL;
    foreach (['products', 'media', 'users', 'orders', 'system_settings'] as $table) {
        if (isset($verified[$table])) {
            echo $table.': '.$verified[$table]['count'].' rows'.PHP_EOL;
        }
    }
    echo 'Database verification fingerprint: '.hash('sha256', json_encode($verified, JSON_THROW_ON_ERROR)).PHP_EOL;
    echo "Transfer verification passed. Source SQLite and .env were preserved.\n";
} catch (Throwable $exception) {
    if ($inTransaction && $target?->inTransaction()) {
        $target->rollBack();
    }
    // PDO/Laravel exception messages can contain SQL values or credentials.
    // Our own guard messages contain only fixed text and validated identifiers.
    $reason = get_class($exception) === RuntimeException::class
        ? $exception->getMessage()
        : get_class($exception).' (code '.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $exception->getCode()).'); details suppressed to protect private data.';
    fwrite(STDERR, 'Transfer stopped during '.$phase.': '.$reason.PHP_EOL);
    fwrite(STDERR, "The source and application connection were not changed. A newly-created target schema may remain for inspection.\n");
    exit(1);
} finally {
    if ($source?->inTransaction()) {
        $source->rollBack();
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
