<?php

namespace Tests\Feature;

use Tests\TestCase;

class RestoreDrillScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/restore-drill-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        @rmdir($dir);
    }

    private function runDrill(array $files, array $extra = []): array
    {
        $env = array_merge(getenv(), [
            'DRILL_DB_NAME' => 'review_drill',
            'DRILL_DB_USER' => 'review',
            'DRILL_DB_PASSWORD' => 'synthetic-password',
            'DRILL_BACKUP_DB' => $files['db'],
            'DRILL_BACKUP_STORAGE' => $files['storage'],
            'DRILL_BACKUP_ENV' => $files['env'],
        ], $extra);

        $process = proc_open(
            ['bash', base_path('../scripts/restore-drill.sh'), '--dry-run'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );

        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $code = proc_close($process);

        return [$code, $output];
    }

    private function validBackups(): array
    {
        $source = $this->dir.'/storage-source';
        mkdir($source);
        file_put_contents($source.'/asset.txt', 'contenido');

        $db = $this->dir.'/dump.sql';
        file_put_contents($db, "-- dump\nCREATE TABLE users (id INT);\n");

        $storage = $this->dir.'/storage.tar.gz';
        exec('tar -czf '.escapeshellarg($storage).' -C '.escapeshellarg($source).' asset.txt', $_, $code);
        $this->assertSame(0, $code, 'tar fixture');

        $env = $this->dir.'/.env.restore';
        file_put_contents($env, "APP_KEY=base64:fixture\n");

        return ['db' => $db, 'storage' => $storage, 'env' => $env];
    }

    public function test_dry_run_rejects_empty_backup_components(): void
    {
        $files = [
            'db' => $this->dir.'/empty.sql',
            'storage' => $this->dir.'/empty.tar.gz',
            'env' => $this->dir.'/empty.env',
        ];

        foreach ($files as $path) {
            file_put_contents($path, '');
        }

        [$code, $output] = $this->runDrill($files);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('Comprobaciones superadas', $output);
        $this->assertStringContainsString('vacio', $output);
    }

    public function test_dry_run_accepts_complete_backups(): void
    {
        [$code, $output] = $this->runDrill($this->validBackups());

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Comprobaciones superadas', $output);
    }

    public function test_dry_run_rejects_a_mismatched_checksum_manifest(): void
    {
        $files = $this->validBackups();
        $manifest = $this->dir.'/SHA256SUMS';
        file_put_contents($manifest, str_repeat('0', 64).'  dump.sql'."\n");

        [$code, $output] = $this->runDrill($files, ['DRILL_BACKUP_SHA256' => $manifest]);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('Comprobaciones superadas', $output);
    }
}
