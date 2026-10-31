<?php
namespace Azera\Tests\Security;

require_once __DIR__ . '/../../vendor/autoload.php';

use Azera\Sync\Schema\SqliteSchemaProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the SQLite PRAGMA identifier interpolation of the 2026
 * security review: `PRAGMA table_info('$table')` let a crafted table or index
 * name break out of the string literal.
 */
class SqliteSchemaProviderRegressionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE "users" (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX "users_email_idx" ON "users" (email)');
    }

    public function testColumnsAreReadForNormalTableName(): void
    {
        $provider = new SqliteSchemaProvider($this->pdo);
        $schema   = $provider->getTableSchema('users');

        $names = array_map(fn($c) => $c->name, $schema->columns);

        $this->assertContains('id', $names);
        $this->assertContains('email', $names);
    }

    public function testIndexesAreReadForNormalTableName(): void
    {
        $provider = new SqliteSchemaProvider($this->pdo);
        $schema   = $provider->getTableSchema('users');

        $names = array_map(fn($i) => $i->name, $schema->indexes);

        $this->assertContains('users_email_idx', $names);
    }

    /**
     * A quote in the table name must be neutralised instead of terminating
     * the PRAGMA literal.
     */
    public function testQuoteInTableNameDoesNotBreakOut(): void
    {
        // A table name containing a quote is legal in SQLite.
        $this->pdo->exec('CREATE TABLE "we""ird" (id INTEGER)');

        $provider = new SqliteSchemaProvider($this->pdo);
        $schema   = $provider->getTableSchema('we"ird');

        $this->assertSame('we"ird', $schema->name);
        $this->assertCount(1, $schema->columns);
        $this->assertSame('id', $schema->columns[0]->name);
    }

    /**
     * The documented injection payload must not execute a second statement.
     */
    public function testInjectionPayloadIsTreatedAsIdentifier(): void
    {
        $this->pdo->exec('CREATE TABLE "victim" (id INTEGER)');

        $provider = new SqliteSchemaProvider($this->pdo);

        try {
            $schema = $provider->getTableSchema("users'); DROP TABLE victim; --");
        } catch (\Throwable $e) {
            // A driver error is acceptable; dropping the table is not.
            $this->assertTrue(true);
        }

        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('victim', $tables, 'victim table must still exist');
    }
}
