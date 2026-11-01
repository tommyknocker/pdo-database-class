<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\GenerationCancelledException;
use tommyknocker\pdodb\cli\ModelGenerator;

/**
 * Declining to overwrite a file must end the generator with an exception the command can handle, not with exit().
 */
final class GeneratorCancellationTests extends BaseSharedTestCase
{
    public function testDecliningOverwriteThrowsInsteadOfExiting(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('gen_cancel');
        $schema->createTable('gen_cancel', ['id' => $schema->primaryKey()]);

        $dir = sys_get_temp_dir() . '/pdodb_gen_cancel_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/GenCancel.php', '<?php // keep');
        putenv('PDODB_NON_INTERACTIVE=1');

        ob_start();

        try {
            ModelGenerator::generate('GenCancel', 'gen_cancel', $dir, self::$db);
            $this->fail('An existing file that is not confirmed for overwrite must stop the generator.');
        } catch (GenerationCancelledException) {
            $this->assertSame('<?php // keep', file_get_contents($dir . '/GenCancel.php'));
        } finally {
            ob_end_clean();
            putenv('PDODB_NON_INTERACTIVE');
            unlink($dir . '/GenCancel.php');
            rmdir($dir);
            $schema->dropTableIfExists('gen_cancel');
        }
    }
}
