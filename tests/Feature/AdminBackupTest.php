<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminBackupService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AdminBackupTest extends TestCase
{
    public function test_only_admins_can_access_backup_endpoints(): void
    {
        $this->getJson('/api/admin/backups')->assertUnauthorized();

        $this->actingAs(new User(['role' => 'CLIENT']))
            ->getJson('/api/admin/backups')
            ->assertForbidden();
    }

    public function test_admin_can_list_server_stored_backups(): void
    {
        $service = Mockery::mock(AdminBackupService::class);
        $service->shouldReceive('backups')->once()->andReturn([
            [
                'id' => '20261118_020000_manual_12345678-1234-4234-8234-123456789abc',
                'created_at' => '2026-11-18T02:00:00+08:00',
                'type' => 'manual',
                'size_bytes' => 1024,
            ],
        ]);
        $this->app->instance(AdminBackupService::class, $service);

        $this->actingAs(new User(['role' => 'ADMIN']))
            ->getJson('/api/admin/backups')
            ->assertOk()
            ->assertJsonPath('backups.0.type', 'manual')
            ->assertJsonPath('backups.0.size_bytes', 1024);
    }

    public function test_admin_can_save_an_automatic_backup_schedule(): void
    {
        $service = Mockery::mock(AdminBackupService::class);
        $service->shouldReceive('updateSettings')
            ->once()
            ->with(true, 'weekly', '04:30')
            ->andReturn([
                'enabled' => true,
                'frequency' => 'weekly',
                'time' => '04:30',
                'next_backup_at' => '2026-11-22T04:30:00+08:00',
            ]);
        $this->app->instance(AdminBackupService::class, $service);

        $this->actingAs(new User(['role' => 'ADMIN']))
            ->putJson('/api/admin/backups/settings', [
                'enabled' => true,
                'frequency' => 'weekly',
                'time' => '04:30',
            ])
            ->assertOk()
            ->assertJsonPath('settings.frequency', 'weekly')
            ->assertJsonPath('settings.time', '04:30');
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $this->actingAs(new User(['role' => 'ADMIN']))
            ->postJson('/api/admin/backups/'.str()->uuid().'/restore')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_manual_backup_reports_unsupported_database_drivers(): void
    {
        config(['database.default' => 'sqlite']);

        $this->actingAs(new User(['role' => 'ADMIN']))
            ->postJson('/api/admin/backups')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Database backup and restore currently support MySQL and MariaDB only.'
            );
    }

    public function test_automatic_retention_removes_only_automatic_backups_older_than_30_days(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $oldAutomatic = 'backups/'.now()->subDays(31)->format('Ymd_His').'_automatic_'.str()->uuid().'.zip';
        $recentAutomatic = 'backups/'.now()->subDays(10)->format('Ymd_His').'_automatic_'.str()->uuid().'.zip';
        $oldManual = 'backups/'.now()->subDays(31)->format('Ymd_His').'_manual_'.str()->uuid().'.zip';
        $oldSafety = 'backups/'.now()->subDays(31)->format('Ymd_His').'_safety_'.str()->uuid().'.zip';

        foreach ([$oldAutomatic, $recentAutomatic, $oldManual, $oldSafety] as $path) {
            $disk->put($path, 'test backup');
        }

        (new AdminBackupService)->pruneExpiredAutomaticBackups();

        $this->assertFalse($disk->exists($oldAutomatic));
        $this->assertTrue($disk->exists($recentAutomatic));
        $this->assertTrue($disk->exists($oldManual));
        $this->assertTrue($disk->exists($oldSafety));

        $disk->deleteDirectory('backups');
    }
}
