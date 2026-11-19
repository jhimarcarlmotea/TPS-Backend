<?php

namespace App\Services;

use App\Exceptions\BackupOperationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class AdminBackupService
{
    private const BACKUP_DIRECTORY = 'backups';

    private const SETTINGS_FILE = 'backups/settings.json';

    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    private const AUTOMATIC_RETENTION_DAYS = 30;

    public function backups(): array
    {
        $disk = Storage::disk('local');
        $records = [];

        foreach ($disk->files(self::BACKUP_DIRECTORY) as $path) {
            $filename = basename($path);
            if (! preg_match('/^(\d{8}_\d{6})_(manual|automatic|safety)_([a-f0-9-]{36})\.zip$/', $filename, $matches)) {
                continue;
            }

            $createdAt = Carbon::createFromFormat('Ymd_His', $matches[1], config('app.timezone'));
            if (! $createdAt) {
                continue;
            }

            $records[] = [
                'id' => substr($filename, 0, -4),
                'created_at' => $createdAt->toIso8601String(),
                'type' => $matches[2],
                'size_bytes' => $disk->size($path),
            ];
        }

        usort($records, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $records;
    }

    public function create(string $type = 'manual'): array
    {
        if (! in_array($type, ['manual', 'automatic', 'safety'], true)) {
            throw new BackupOperationException('Invalid backup type.', 422);
        }

        $connection = $this->databaseConnection();
        $this->assertLocalUploadsDisk();

        $id = now()->format('Ymd_His').'_'.$type.'_'.strtolower((string) str()->uuid());
        $backupDirectory = Storage::disk('local')->path(self::BACKUP_DIRECTORY);
        $workingDirectory = $backupDirectory.DIRECTORY_SEPARATOR.'.tmp-'.str()->uuid();
        File::ensureDirectoryExists($workingDirectory, 0700, true);

        $dumpPath = $workingDirectory.DIRECTORY_SEPARATOR.'database.jsonl';
        $archivePath = $workingDirectory.DIRECTORY_SEPARATOR.'backup.zip';
        $finalRelativePath = self::BACKUP_DIRECTORY.'/'.$id.'.zip';
        $finalPath = Storage::disk('local')->path($finalRelativePath);

        try {
            $this->dumpDatabase($dumpPath);
            $this->createArchive($archivePath, $dumpPath, $connection['driver']);

            if (! @rename($archivePath, $finalPath)) {
                throw new BackupOperationException('The backup could not be saved to server storage.', 500);
            }

            return $this->backupRecord($id, $finalPath);
        } finally {
            if (is_dir($workingDirectory)) {
                File::deleteDirectory($workingDirectory);
            }
        }
    }

    public function downloadPath(string $id): string
    {
        if (! preg_match('/^\d{8}_\d{6}_(manual|automatic|safety)_[a-f0-9-]{36}$/', $id)) {
            throw new BackupOperationException('Backup not found.', 404);
        }

        $relativePath = self::BACKUP_DIRECTORY.'/'.$id.'.zip';
        $disk = Storage::disk('local');
        if (! $disk->exists($relativePath)) {
            throw new BackupOperationException('Backup not found.', 404);
        }

        return $disk->path($relativePath);
    }

    public function settings(): array
    {
        $settings = $this->readSettings();

        return [
            'enabled' => $settings['enabled'],
            'frequency' => $settings['frequency'],
            'time' => $settings['time'],
            'next_backup_at' => $settings['enabled'] ? $this->nextBackupAt($settings)->toIso8601String() : null,
        ];
    }

    public function updateSettings(bool $enabled, string $frequency, string $time): array
    {
        $settings = $this->readSettings();
        $settings['enabled'] = $enabled;
        $settings['frequency'] = $frequency;
        $settings['time'] = $time;
        $this->writeSettings($settings);

        return $this->settings();
    }

    public function runScheduledBackup(): bool
    {
        $settings = $this->readSettings();
        if (! $settings['enabled'] || ! $this->isBackupDue($settings)) {
            return false;
        }

        $this->create('automatic');
        $settings['last_auto_backup_at'] = now(config('app.timezone'))->toIso8601String();
        $this->writeSettings($settings);
        $this->pruneExpiredAutomaticBackups();

        return true;
    }

    public function restore(string $id): array
    {
        $targetArchive = $this->downloadPath($id);
        $connection = $this->databaseConnection();
        $this->assertLocalUploadsDisk();

        $workingDirectory = Storage::disk('local')->path(self::BACKUP_DIRECTORY).DIRECTORY_SEPARATOR.'.restore-'.str()->uuid();
        File::ensureDirectoryExists($workingDirectory, 0700, true);

        try {
            $target = $this->unpackArchive($targetArchive, $workingDirectory, $connection['driver']);
            $safetyBackup = $this->create('safety');

            try {
                $this->importDatabase($target['database']);
                $this->replaceUploads($target['uploads']);
            } catch (Throwable $restoreError) {
                try {
                    $safetyDirectory = $workingDirectory.DIRECTORY_SEPARATOR.'safety';
                    File::ensureDirectoryExists($safetyDirectory, 0700, true);
                    $safety = $this->unpackArchive(
                        $this->downloadPath($safetyBackup['id']),
                        $safetyDirectory,
                        $connection['driver']
                    );
                    $this->importDatabase($safety['database']);
                    $this->replaceUploads($safety['uploads']);
                } catch (Throwable $recoveryError) {
                    Log::critical('Backup restore and automatic recovery both failed.', [
                        'backup_id' => $id,
                        'safety_backup_id' => $safetyBackup['id'],
                        'restore_error' => $restoreError->getMessage(),
                        'recovery_error' => $recoveryError->getMessage(),
                    ]);

                    throw new BackupOperationException(
                        "Restore and automatic recovery failed. Safety backup {$safetyBackup['id']} is available on the server.",
                        500,
                        $restoreError,
                    );
                }

                throw new BackupOperationException(
                    'Restore failed. The previous database and uploaded files were recovered from a safety backup.',
                    500,
                    $restoreError,
                );
            }

            return [
                'restored_backup' => $this->recordFromPath($id, $targetArchive),
                'safety_backup' => $safetyBackup,
            ];
        } finally {
            if (is_dir($workingDirectory)) {
                File::deleteDirectory($workingDirectory);
            }
        }
    }

    private function databaseConnection(): array
    {
        $driver = config('database.default');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new BackupOperationException('Database backup and restore currently support MySQL and MariaDB only.', 422);
        }

        $connection = config("database.connections.{$driver}");
        if (! is_array($connection)) {
            throw new BackupOperationException('The configured database connection is unavailable.', 500);
        }

        if (! empty($connection['url'])) {
            $url = parse_url($connection['url']);
            if (! is_array($url) || empty($url['path'])) {
                throw new BackupOperationException('The configured database URL is invalid.', 500);
            }

            $connection['host'] = $url['host'] ?? $connection['host'] ?? '127.0.0.1';
            $connection['port'] = $url['port'] ?? $connection['port'] ?? 3306;
            $connection['username'] = isset($url['user']) ? rawurldecode($url['user']) : ($connection['username'] ?? '');
            $connection['password'] = isset($url['pass']) ? rawurldecode($url['pass']) : ($connection['password'] ?? '');
            $connection['database'] = ltrim($url['path'], '/');
        }

        if (empty($connection['database'])) {
            throw new BackupOperationException('The configured database name is missing.', 500);
        }

        $connection['driver'] = $driver;

        return $connection;
    }

    private function assertLocalUploadsDisk(): void
    {
        if (config('filesystems.disks.public.driver') !== 'local') {
            throw new BackupOperationException('Backups currently require the public uploads disk to use local storage.', 422);
        }
    }

    private function dumpDatabase(string $dumpPath): void
    {
        $pdo = DB::connection()->getPdo();
        $handle = @fopen($dumpPath, 'wb');
        if (! is_resource($handle)) {
            throw new BackupOperationException('A temporary database backup file could not be created.', 500);
        }

        try {
            $this->assertSupportedDatabaseObjects($pdo);
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $table) {
                $tableName = (string) $table[0];
                $quotedTable = $this->quoteIdentifier($tableName);
                $createRow = $pdo->query('SHOW CREATE TABLE '.$quotedTable)->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? null;
                if (! is_string($createSql) || $createSql === '') {
                    throw new RuntimeException("The schema for table {$tableName} could not be read.");
                }

                $this->writeJsonLine($handle, [
                    'type' => 'table',
                    'name' => $tableName,
                    'create_sql' => $createSql,
                ]);

                $columns = $pdo->query('SHOW FULL COLUMNS FROM '.$quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_values(array_filter(
                    $columns,
                    fn (array $column): bool => ! str_contains(strtoupper((string) ($column['Extra'] ?? '')), 'GENERATED')
                ));
                $columnNames = array_map(fn (array $column): string => (string) $column['Field'], $columns);

                $this->writeJsonLine($handle, [
                    'type' => 'columns',
                    'table' => $tableName,
                    'names' => $columnNames,
                ]);

                if ($columnNames !== []) {
                    $selectColumns = implode(', ', array_map(
                        fn (string $column): string => $this->quoteIdentifier($column),
                        $columnNames
                    ));
                    $rows = $pdo->query('SELECT '.$selectColumns.' FROM '.$quotedTable);
                    while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                        $values = [];
                        foreach ($row as $value) {
                            if (is_resource($value)) {
                                $value = stream_get_contents($value);
                                if ($value === false) {
                                    throw new RuntimeException("A value in table {$tableName} could not be read.");
                                }
                            }
                            $values[] = $value === null ? null : base64_encode((string) $value);
                        }

                        $this->writeJsonLine($handle, [
                            'type' => 'row',
                            'values' => $values,
                        ]);
                    }
                }

                $this->writeJsonLine($handle, ['type' => 'end_table']);
            }

            $pdo->commit();
        } catch (BackupOperationException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Log::error('Database backup export failed.', ['error' => $exception->getMessage()]);
            throw new BackupOperationException('Database backup failed. Check the server logs for details.', 500, $exception);
        } finally {
            fclose($handle);
        }

        if (! is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new BackupOperationException('The database backup produced an empty file.', 500);
        }
    }

    private function importDatabase(string $dumpPath): void
    {
        $handle = @fopen($dumpPath, 'rb');
        if (! is_resource($handle)) {
            throw new BackupOperationException('The database backup file could not be read.', 500);
        }

        $pdo = DB::connection()->getPdo();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $object) {
                $name = $this->quoteIdentifier((string) $object[0]);
                if (strtoupper((string) ($object[1] ?? '')) === 'VIEW') {
                    $pdo->exec('DROP VIEW IF EXISTS '.$name);
                } else {
                    $pdo->exec('DROP TABLE IF EXISTS '.$name);
                }
            }

            $tableName = null;
            $columns = [];
            $insert = null;

            while (($line = fgets($handle)) !== false) {
                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RuntimeException('The database backup contains invalid JSON.', 0, $exception);
                }

                if (! is_array($record) || ! isset($record['type'])) {
                    throw new RuntimeException('The database backup contains an invalid record.');
                }

                if ($record['type'] === 'table') {
                    if (! is_string($record['name'] ?? null) || ! is_string($record['create_sql'] ?? null)) {
                        throw new RuntimeException('The database backup contains an invalid table definition.');
                    }
                    $tableName = $record['name'];
                    $columns = [];
                    $insert = null;
                    $pdo->exec($record['create_sql']);
                } elseif ($record['type'] === 'columns') {
                    if (
                        ! is_string($tableName)
                        || ($record['table'] ?? null) !== $tableName
                        || ! is_array($record['names'] ?? null)
                    ) {
                        throw new RuntimeException('The database backup contains invalid table columns.');
                    }

                    $columns = array_map('strval', $record['names']);
                    if ($columns !== []) {
                        $quotedColumns = implode(', ', array_map(
                            fn (string $column): string => $this->quoteIdentifier($column),
                            $columns
                        ));
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $insert = $pdo->prepare(
                            'INSERT INTO '.$this->quoteIdentifier($tableName).' ('.$quotedColumns.') VALUES ('.$placeholders.')'
                        );
                    }
                } elseif ($record['type'] === 'row') {
                    if (! $insert instanceof \PDOStatement || ! is_array($record['values'] ?? null) || count($record['values']) !== count($columns)) {
                        throw new RuntimeException('The database backup contains an invalid table row.');
                    }

                    foreach ($record['values'] as $index => $encodedValue) {
                        if ($encodedValue === null) {
                            $insert->bindValue($index + 1, null, PDO::PARAM_NULL);

                            continue;
                        }

                        if (! is_string($encodedValue) || ($value = base64_decode($encodedValue, true)) === false) {
                            throw new RuntimeException('The database backup contains an invalid encoded value.');
                        }
                        $insert->bindValue($index + 1, $value, PDO::PARAM_STR);
                    }

                    $insert->execute();
                } elseif ($record['type'] !== 'end_table') {
                    throw new RuntimeException('The database backup contains an unknown record type.');
                }
            }

            if (! feof($handle)) {
                throw new RuntimeException('The database backup could not be read completely.');
            }

        } catch (Throwable $exception) {
            Log::error('Database restore import failed.', ['error' => $exception->getMessage()]);
            throw new BackupOperationException('Database restore failed. Check the server logs for details.', 500, $exception);
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } finally {
                fclose($handle);
            }
        }
    }

    private function assertSupportedDatabaseObjects(PDO $pdo): void
    {
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $unsupportedObjects = [
            'triggers' => ['TRIGGERS', 'TRIGGER_SCHEMA'],
            'routines' => ['ROUTINES', 'ROUTINE_SCHEMA'],
            'events' => ['EVENTS', 'EVENT_SCHEMA'],
        ];

        foreach ($unsupportedObjects as $name => [$table, $schemaColumn]) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.{$table} WHERE {$schemaColumn} = ?");
            $statement->execute([$schema]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new BackupOperationException("Database backups do not support {$name}.", 422);
            }
        }

        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $object) {
            if (strtoupper((string) ($object[1] ?? '')) === 'VIEW') {
                throw new BackupOperationException('Database backups do not support views.', 422);
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function writeJsonLine($handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL;
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The database backup file could not be written.');
            }
            $offset += $written;
        }
    }

    private function createArchive(string $archivePath, string $dumpPath, string $driver): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupOperationException('A backup archive could not be created.', 500);
        }

        $closed = false;
        try {
            if (! $zip->addFile($dumpPath, 'database.jsonl')) {
                throw new BackupOperationException('The database dump could not be added to the backup archive.', 500);
            }

            $uploadsPath = config('filesystems.disks.public.root');
            File::ensureDirectoryExists($uploadsPath, 0755, true);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $relativePath = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($uploadsPath) + 1)
                );

                if (! $zip->addFile($file->getPathname(), 'uploads/'.$relativePath)) {
                    throw new BackupOperationException('An uploaded file could not be added to the backup archive.', 500);
                }
            }

            $manifest = json_encode([
                'format_version' => 2,
                'driver' => $driver,
                'created_at' => now(config('app.timezone'))->toIso8601String(),
                'includes' => ['database', 'uploads'],
            ], JSON_THROW_ON_ERROR);

            if (! $zip->addFromString('manifest.json', $manifest)) {
                throw new BackupOperationException('The backup manifest could not be added to the archive.', 500);
            }

            if (! $zip->close()) {
                throw new BackupOperationException('The backup archive could not be finalized.', 500);
            }
            $closed = true;
        } finally {
            if (! $closed) {
                $zip->close();
            }
        }
    }

    private function unpackArchive(string $archivePath, string $destination, string $driver): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new BackupOperationException('The selected backup archive is damaged or unreadable.', 422);
        }

        try {
            $manifestContents = $zip->getFromName('manifest.json');
            if (! is_string($manifestContents) || strlen($manifestContents) > 8192) {
                throw new BackupOperationException('The selected file is not a valid system backup.', 422);
            }

            try {
                $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new BackupOperationException('The selected backup manifest is invalid.', 422, $exception);
            }

            if (
                ($manifest['format_version'] ?? null) !== 2
                || ($manifest['driver'] ?? null) !== $driver
                || ($manifest['includes'] ?? null) !== ['database', 'uploads']
            ) {
                throw new BackupOperationException('The selected backup is incompatible with this database or storage configuration.', 422);
            }

            $databaseFound = false;
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if (! is_string($entry) || ! $this->isSafeArchiveEntry($entry)) {
                    throw new BackupOperationException('The selected backup contains an unsafe archive path.', 422);
                }

                if ($entry === 'database.jsonl') {
                    $databaseFound = true;
                    $entries[] = $entry;

                    continue;
                }

                if ($entry === 'manifest.json') {
                    continue;
                }

                if (str_starts_with($entry, 'uploads/')) {
                    $entries[] = $entry;

                    continue;
                }

                throw new BackupOperationException('The selected backup contains an unexpected archive entry.', 422);
            }

            if (! $databaseFound) {
                throw new BackupOperationException('The selected backup does not contain a database dump.', 422);
            }

            File::ensureDirectoryExists($destination, 0700, true);
            foreach ($entries as $entry) {
                $this->extractArchiveEntry($zip, $entry, $destination);
            }

            $databasePath = $destination.DIRECTORY_SEPARATOR.'database.jsonl';
            if (! is_file($databasePath) || filesize($databasePath) === 0) {
                throw new BackupOperationException('The selected database dump is empty or missing.', 422);
            }

            $uploadsPath = $destination.DIRECTORY_SEPARATOR.'uploads';
            File::ensureDirectoryExists($uploadsPath, 0700, true);

            return ['database' => $databasePath, 'uploads' => $uploadsPath];
        } finally {
            $zip->close();
        }
    }

    private function isSafeArchiveEntry(string $entry): bool
    {
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '\\') || str_contains($entry, "\0")) {
            return false;
        }

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    private function extractArchiveEntry(ZipArchive $zip, string $entry, string $destination): void
    {
        $input = $zip->getStream($entry);
        if (! is_resource($input)) {
            throw new BackupOperationException('A file in the backup could not be read.', 422);
        }

        $targetPath = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry);
        File::ensureDirectoryExists(dirname($targetPath), 0700, true);
        $output = @fopen($targetPath, 'wb');
        if (! is_resource($output)) {
            fclose($input);
            throw new BackupOperationException('A file in the backup could not be extracted to temporary storage.', 500);
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new BackupOperationException('A file in the backup could not be extracted.', 500);
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function replaceUploads(string $stagedUploads): void
    {
        $liveUploads = config('filesystems.disks.public.root');
        $oldUploads = $liveUploads.'.restore-old-'.str()->uuid();
        File::ensureDirectoryExists(dirname($liveUploads), 0755, true);

        $hadPreviousUploads = is_dir($liveUploads);
        if ($hadPreviousUploads && ! @rename($liveUploads, $oldUploads)) {
            throw new BackupOperationException('Uploaded files could not be moved aside for restore.', 500);
        }

        try {
            if (! @rename($stagedUploads, $liveUploads)) {
                throw new BackupOperationException('Uploaded files could not be restored from the backup.', 500);
            }
        } catch (Throwable $exception) {
            if ($hadPreviousUploads && is_dir($oldUploads) && ! is_dir($liveUploads)) {
                @rename($oldUploads, $liveUploads);
            }
            throw $exception;
        }

        if ($hadPreviousUploads && is_dir($oldUploads) && ! File::deleteDirectory($oldUploads)) {
            Log::warning('A replaced uploads directory could not be removed after backup restore.', [
                'path' => $oldUploads,
            ]);
        }
    }

    private function backupRecord(string $id, string $path): array
    {
        return $this->recordFromPath($id, $path);
    }

    private function recordFromPath(string $id, string $path): array
    {
        $parts = explode('_', $id);
        $createdAt = Carbon::createFromFormat(
            'Ymd_His',
            ($parts[0] ?? '').'_'.($parts[1] ?? ''),
            config('app.timezone')
        );

        return [
            'id' => $id,
            'created_at' => $createdAt?->toIso8601String(),
            'type' => $parts[2] ?? 'manual',
            'size_bytes' => filesize($path),
        ];
    }

    private function readSettings(): array
    {
        $defaults = [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'last_auto_backup_at' => null,
        ];

        $disk = Storage::disk('local');
        if (! $disk->exists(self::SETTINGS_FILE)) {
            return $defaults;
        }

        try {
            $saved = json_decode($disk->get(self::SETTINGS_FILE), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Log::error('Saved backup schedule settings are invalid.', ['error' => $exception->getMessage()]);
            throw new BackupOperationException('Saved backup settings could not be read. Check the server logs.', 500, $exception);
        }

        if (
            ! is_array($saved)
            || ! is_bool($saved['enabled'] ?? null)
            || ! in_array($saved['frequency'] ?? null, self::FREQUENCIES, true)
            || ! preg_match('/^\d{2}:\d{2}$/', (string) ($saved['time'] ?? ''))
        ) {
            throw new BackupOperationException('Saved backup settings are invalid. Check the server logs.', 500);
        }

        return array_merge($defaults, $saved);
    }

    private function writeSettings(array $settings): void
    {
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put(self::SETTINGS_FILE, $json)) {
            throw new BackupOperationException('Backup settings could not be saved to server storage.', 500);
        }
    }

    private function isBackupDue(array $settings): bool
    {
        $now = Carbon::now(config('app.timezone'));
        if ($now->format('H:i') !== $settings['time']) {
            return false;
        }

        $lastBackup = isset($settings['last_auto_backup_at'])
            ? Carbon::parse($settings['last_auto_backup_at'], config('app.timezone'))
            : null;

        return match ($settings['frequency']) {
            'daily' => ! $lastBackup?->isSameDay($now),
            'weekly' => $now->isSunday() && ! ($lastBackup && $lastBackup->isSameWeek($now)),
            'monthly' => $now->day === 1 && ! ($lastBackup && $lastBackup->isSameMonth($now)),
            default => false,
        };
    }

    private function nextBackupAt(array $settings): Carbon
    {
        $now = Carbon::now(config('app.timezone'));
        for ($offset = 0; $offset <= 35; $offset++) {
            $candidate = $now->copy()->addDays($offset)->setTimeFromTimeString($settings['time']);
            $matchesFrequency = match ($settings['frequency']) {
                'daily' => true,
                'weekly' => $candidate->isSunday(),
                'monthly' => $candidate->day === 1,
                default => false,
            };

            if ($candidate->isFuture() && $matchesFrequency) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to calculate the next automatic backup date.');
    }

    public function pruneExpiredAutomaticBackups(): void
    {
        $cutoff = Carbon::now(config('app.timezone'))->subDays(self::AUTOMATIC_RETENTION_DAYS);
        $disk = Storage::disk('local');

        foreach ($this->backups() as $backup) {
            if ($backup['type'] !== 'automatic' || Carbon::parse($backup['created_at'])->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            if (! $disk->delete(self::BACKUP_DIRECTORY.'/'.$backup['id'].'.zip')) {
                Log::warning('An expired automatic backup could not be removed.', [
                    'backup_id' => $backup['id'],
                ]);
            }
        }
    }
}
