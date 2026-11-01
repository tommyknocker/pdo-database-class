<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Runs a migration whose second statement fails and reports what survived.
 */
final class MigrationTransactionSupport
{
    /**
     * @param string $transactional PHP expression for Migration::transactional(), e.g. 'null', 'true', 'false'
     *
     * @return array{message: string, tableExists: bool, recorded: bool}
     */
    public static function runFailingMigration(PdoDb $db, string $transactional = 'null'): array
    {
        $suffix = bin2hex(random_bytes(4));
        $table = 'mig_partial_' . $suffix;
        $dir = sys_get_temp_dir() . '/pdodb_mig_tx_' . $suffix;
        mkdir($dir, 0755, true);
        $version = '20260101000000_partial_' . $suffix;
        $class = 'm20260101000000Partial' . ucfirst($suffix);
        file_put_contents($dir . "/m{$version}.php", <<<PHP
        <?php
        use tommyknocker\\pdodb\\migrations\\Migration;

        class {$class} extends Migration
        {
            public function transactional(): ?bool
            {
                return {$transactional};
            }

            public function up(): void
            {
                \$this->schema()->createTable('{$table}', ['id' => \$this->schema()->primaryKey()]);
                \$this->schema()->createTable('{$table}', ['id' => \$this->schema()->primaryKey()]);
            }

            public function down(): void
            {
                \$this->schema()->dropTableIfExists('{$table}');
            }
        }
        PHP);

        $db->schema()->dropTableIfExists('__migrations');
        $message = '';

        try {
            (new MigrationRunner($db, $dir))->migrate();
        } catch (QueryException $e) {
            $message = $e->getMessage();
        }

        $tableExists = $db->schema()->tableExists($table);
        $recorded = (new MigrationRunner($db, $dir))->getMigrationHistory() !== [];

        $db->schema()->dropTableIfExists($table);
        $db->schema()->dropTableIfExists('__migrations');
        unlink($dir . "/m{$version}.php");
        rmdir($dir);

        return ['message' => $message, 'tableExists' => $tableExists, 'recorded' => $recorded];
    }
}
