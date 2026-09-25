<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests;

use SelectiveUndo\Bootstrap\Plugin;
use SelectiveUndo\Bootstrap\Services;
use SelectiveUndo\Application\Capture\CaptureState;
use SelectiveUndo\Infrastructure\Settings\Settings;

/**
 * Runs inside a real WordPress site with the plugin active.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Services $s;
    protected \wpdb $db;

    public function setUp(): void
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->s = Plugin::services();
        $this->truncateJournal();
        update_option(Settings::OPTION, $this->s->settings()->defaults());
        delete_option(CaptureState::OPTION);
        $this->s->settings()->flush();
        $this->s->requestContext()->reset();
        wp_set_current_user(1);
    }

    public function tearDown(): void
    {
        $this->endRequest();
    }

    /** Simulates the end of the current HTTP request and the start of a new one. */
    protected function endRequest(): void
    {
        $this->s->observer()->onShutdown();
        $this->s->capture()->sealRequestChangesets();
        $this->s->requestContext()->reset();
        wp_cache_flush();
    }

    protected function truncateJournal(): void
    {
        foreach ($this->s->tables()->own() as $table) {
            $this->db->query("TRUNCATE TABLE `{$table}`");
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function createPost(array $args = []): int
    {
        $id = wp_insert_post(array_merge([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Контакты',
            'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
            'post_excerpt' => '',
        ], $args), true);

        if ($id instanceof \WP_Error) {
            throw new \RuntimeException($id->get_error_message());
        }

        $this->endRequest();

        return (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function changes(int $postId, ?string $event = null): array
    {
        $t = $this->s->tables();
        $sql = $this->db->prepare(
            "SELECT * FROM {$t->changes} WHERE object_type = 'post' AND object_id = %d ORDER BY id",
            $postId
        );
        $rows = (array) $this->db->get_results($sql, ARRAY_A);

        return array_values(array_filter($rows, static fn (array $r): bool => $event === null || $r['event'] === $event));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function changesets(): array
    {
        return (array) $this->db->get_results("SELECT * FROM {$this->s->tables()->changesets} ORDER BY id", ARRAY_A);
    }

    protected function blobValue(int $blobId, string $hash): mixed
    {
        return $this->s->blobs()->loadVerified($blobId, $hash)->value;
    }

    protected function userId(string $login): int
    {
        $user = get_user_by('login', $login);

        if (!$user instanceof \WP_User) {
            throw new \RuntimeException('Missing test user ' . $login);
        }

        return $user->ID;
    }
}
