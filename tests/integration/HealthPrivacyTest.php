<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

final class HealthPrivacyTest extends IntegrationTestCase
{
    public function testHealthyEnvironmentHasNoRestoreBlockers(): void
    {
        $checks = $this->s->health()->checks();
        $byId = array_column($checks, null, 'id');
        $this->assertSame('ok', $byId['schema']['status']);
        $this->assertSame('ok', $byId['transactions']['status']);
        $this->assertSame('ok', $byId['restore_connection']['status']);
        $this->assertSame('ok', $byId['capture']['status']);
        $this->assertSame([], array_values(array_filter($checks, static fn (array $c): bool => $c['blocks_restore'])));

        $overview = $this->s->health()->overview();
        $this->assertSame('active', $overview['capture']['state']);
        $this->assertSame([], $overview['restore_blockers']);
        $this->assertSame(7, $overview['retention_days']);
    }

    public function testDisabledCaptureIsVisibleNotGreen(): void
    {
        $this->s->settings()->update(['capture_enabled' => false]);
        $overview = $this->s->health()->overview();
        $this->assertSame('disabled', $overview['capture']['state']);
        $byId = array_column($this->s->health()->checks(), null, 'id');
        $this->assertSame('warning', $byId['capture']['status']);
    }

    public function testDiagnosticsExportContainsNoContent(): void
    {
        $id = $this->createPost(['post_title' => 'Top secret title', 'post_content' => 'Secret body']);
        wp_update_post(['ID' => $id, 'post_content' => 'Another secret']);
        $this->endRequest();
        $json = (string) wp_json_encode($this->s->health()->export());
        $this->assertFalse(str_contains($json, 'secret'));
        $this->assertFalse(str_contains($json, 'Top secret'));
        $this->assertFalse(str_contains($json, DB_PASSWORD === '' ? 'no-password' : DB_PASSWORD));
        $this->assertFalse(str_contains($json, (string) get_option('selective_undo_instance_id')));
        $this->assertTrue(str_contains($json, '"schema":1'));
    }

    public function testPrivacyExporterAndEraser(): void
    {
        $editor = $this->userId('editor');
        $id = $this->createPost();
        wp_set_current_user($editor);
        wp_update_post(['ID' => $id, 'post_title' => 'Edited by editor']);
        $this->endRequest();
        wp_set_current_user(1);

        $email = get_userdata($editor)->user_email;
        $export = $this->s->privacy()->export($email);
        $this->assertCount(1, $export['data']);
        $this->assertTrue($export['done']);
        $this->assertFalse(str_contains((string) wp_json_encode($export), 'Edited by editor'), 'values are not personal data exports');

        $erase = $this->s->privacy()->erase($email);
        $this->assertSame(1, $erase['items_removed']);
        $this->assertSame(0, (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->s->tables()->changesets} WHERE actor_user_id = %d", $editor)));
        $this->assertCount(0, $this->s->privacy()->export($email)['data']);
    }

    public function testSettingsValidation(): void
    {
        $settings = $this->s->settings();
        $this->assertThrows(\SelectiveUndo\Domain\DomainError::class, fn () => $settings->update(['retention_days' => 30]));
        $this->assertThrows(\SelectiveUndo\Domain\DomainError::class, fn () => $settings->update(['tracked_post_types' => ['attachment']]));
        $this->assertThrows(\SelectiveUndo\Domain\DomainError::class, fn () => $settings->update(['unknown' => 1]));
        $next = $settings->update(['retention_days' => 3, 'tracked_post_types' => ['post']]);
        $this->assertSame(3, $next['retention_days']);
        $this->assertSame(['post'], $settings->trackedPostTypes());
        $this->assertSame(3, $settings->retentionDays());
    }

    public function testUninstallKeepsDataUnlessAllowed(): void
    {
        $id = $this->createPost();
        wp_update_post(['ID' => $id, 'post_title' => 'Keep me']);
        $this->endRequest();
        $this->runUninstall();
        $this->assertTrue($this->s->schema()->verify() === [], 'tables still exist');
        $this->assertCount(1, $this->changes($id, 'update'));
        $this->assertTrue(user_can(1, 'sundo_view_history'));

        $this->s->settings()->update(['delete_data_on_uninstall' => true]);
        $this->runUninstall();
        $this->assertTrue($this->s->schema()->verify() !== []);
        $this->assertFalse(get_role('administrator')->has_cap('sundo_view_history'));
        $this->assertFalse(get_option('selective_undo_settings'));

        // Restore the test site.
        \SelectiveUndo\Bootstrap\Plugin::activate();
        $this->s->settings()->flush();
        $this->assertSame([], $this->s->schema()->verify());
    }

    private function runUninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'selective-undo/selective-undo.php');
        }

        wp_cache_delete('selective_undo_settings', 'options');
        wp_cache_delete('alloptions', 'options');
        include dirname(__DIR__, 2) . '/uninstall.php';
        wp_cache_flush();
        $this->s->settings()->flush();
    }
}
