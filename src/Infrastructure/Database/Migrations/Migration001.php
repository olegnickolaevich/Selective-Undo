<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database\Migrations;

use SelectiveUndo\Infrastructure\Database\Migration;
use SelectiveUndo\Infrastructure\Database\SchemaError;
use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Initial schema. Identifiers, codes and statuses use ascii_bin; hashes are raw
 * BINARY(32) SHA-256; all DATETIME values are UTC.
 *
 * Foreign keys are not declared (like WordPress core); integrity is maintained
 * by services and garbage collection, and every relation is indexed.
 */
final class Migration001 implements Migration
{
    public function up(\wpdb $db, Tables $t): void
    {
        $collate = $db->get_charset_collate();

        foreach ($this->statements($t, $collate) as $sql) {
            if ($db->query($sql) === false) {
                throw new SchemaError('create_table_failed');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function statements(Tables $t, string $collate): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS {$t->operations} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label           VARCHAR(255) NOT NULL DEFAULT '',
  source          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  actor_user_id   BIGINT UNSIGNED NULL,
  status          VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  metadata_json   TEXT NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  finished_at     DATETIME NULL,
  abandon_after   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  KEY status_abandon (status, abandon_after)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->changesets} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_id        BIGINT UNSIGNED NULL,
  restore_job_id      BIGINT UNSIGNED NULL,
  actor_user_id       BIGINT UNSIGNED NULL,
  kind                VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source              VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  grouping_mode       VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label               VARCHAR(255) NOT NULL DEFAULT '',
  status              VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
  quality             VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'verified',
  change_count        INT UNSIGNED NOT NULL DEFAULT 0,
  object_count        INT UNSIGNED NOT NULL DEFAULT 0,
  is_pinned           TINYINT(1) NOT NULL DEFAULT 0,
  summary_json        TEXT NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  sealed_at           DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  KEY actor_id (actor_user_id, id),
  KEY operation_id (operation_id, id),
  KEY kind_id (kind, id),
  KEY created_id (created_at, id),
  KEY status_updated (status, updated_at),
  KEY restore_job (restore_job_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->blobs} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_hash        BINARY(32) NOT NULL,
  encoding            VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload             LONGBLOB NOT NULL,
  uncompressed_bytes  INT UNSIGNED NOT NULL,
  stored_bytes        INT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL,
  last_seen_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_hash (content_hash),
  KEY last_seen (last_seen_at, id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->changes} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  changeset_id        BIGINT UNSIGNED NOT NULL,
  sequence_no         INT UNSIGNED NOT NULL,
  event               VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_subtype      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  object_id           BIGINT UNSIGNED NOT NULL,
  adapter_key         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  adapter_version     SMALLINT UNSIGNED NOT NULL,
  field_key           VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  before_blob_id      BIGINT UNSIGNED NULL,
  after_blob_id       BIGINT UNSIGNED NULL,
  before_hash         BINARY(32) NULL,
  after_hash          BINARY(32) NULL,
  quality             VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  restorable          TINYINT(1) NOT NULL,
  reason_code         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  reverts_change_id   BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY changeset_seq (changeset_id, sequence_no, field_key),
  KEY object_field (object_type, object_id, field_key, id),
  KEY created_id (created_at, id),
  KEY before_blob (before_blob_id),
  KEY after_blob (after_blob_id),
  KEY reverts (reverts_change_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->gaps} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reason              VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope               VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  object_id           BIGINT UNSIGNED NULL,
  occurrences         INT UNSIGNED NOT NULL DEFAULT 1,
  started_at          DATETIME NOT NULL,
  last_seen_at        DATETIME NOT NULL,
  ended_at            DATETIME NULL,
  details_json        TEXT NULL,
  PRIMARY KEY (id),
  KEY open_reason (ended_at, reason),
  KEY object_ref (object_type, object_id, id),
  KEY started (started_at, id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->plans} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  blog_id             BIGINT UNSIGNED NOT NULL,
  actor_user_id       BIGINT UNSIGNED NOT NULL,
  status              VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  selection_json      TEXT NOT NULL,
  plan_hash           BINARY(32) NULL,
  item_count          INT UNSIGNED NOT NULL DEFAULT 0,
  ready_count         INT UNSIGNED NOT NULL DEFAULT 0,
  object_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL,
  expires_at          DATETIME NOT NULL,
  claimed_job_id      BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY claimed_job (claimed_job_id),
  KEY actor_id (actor_user_id, id),
  KEY status_expires (status, expires_at)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->planItems} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id             BIGINT UNSIGNED NOT NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_subtype      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  object_id           BIGINT UNSIGNED NOT NULL,
  adapter_key         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  adapter_version     SMALLINT UNSIGNED NOT NULL,
  field_key           VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  first_change_id     BIGINT UNSIGNED NOT NULL,
  last_change_id      BIGINT UNSIGNED NOT NULL,
  chain_length        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  expected_hash       BINARY(32) NULL,
  target_hash         BINARY(32) NULL,
  target_blob_id      BIGINT UNSIGNED NULL,
  status              VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  warnings            VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY plan_object_field (plan_id, object_type, object_id, field_key),
  KEY target_blob (target_blob_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->planItemSources} (
  plan_item_id        BIGINT UNSIGNED NOT NULL,
  change_id           BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (plan_item_id, change_id),
  KEY change_id (change_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->jobs} (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan_id               BIGINT UNSIGNED NOT NULL,
  blog_id               BIGINT UNSIGNED NOT NULL,
  actor_user_id         BIGINT UNSIGNED NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  restore_changeset_id  BIGINT UNSIGNED NULL,
  mode                  VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status                VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lease_token           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lease_expires_at      DATETIME NULL,
  fence_seq             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  heartbeat_at          DATETIME NULL,
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  cancel_requested_at   DATETIME NULL,
  total_items           INT UNSIGNED NOT NULL DEFAULT 0,
  restored_items        INT UNSIGNED NOT NULL DEFAULT 0,
  already_items         INT UNSIGNED NOT NULL DEFAULT 0,
  conflict_items        INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_items         INT UNSIGNED NOT NULL DEFAULT 0,
  failed_items          INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_items       INT UNSIGNED NOT NULL DEFAULT 0,
  postprocess_status    VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  created_at            DATETIME NOT NULL,
  started_at            DATETIME NULL,
  finished_at           DATETIME NULL,
  updated_at            DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY plan_id (plan_id),
  UNIQUE KEY actor_idempotency (actor_user_id, idempotency_key_hash),
  KEY status_lease (status, lease_expires_at),
  KEY actor_id (actor_user_id, id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->jobItems} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id              BIGINT UNSIGNED NOT NULL,
  plan_item_id        BIGINT UNSIGNED NOT NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  object_id           BIGINT UNSIGNED NOT NULL,
  status              VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  reason_code         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  error_code          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  attempt_count       INT UNSIGNED NOT NULL DEFAULT 0,
  result_change_id    BIGINT UNSIGNED NULL,
  started_at          DATETIME NULL,
  finished_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY job_plan_item (job_id, plan_item_id),
  KEY job_status_object (job_id, status, object_type, object_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->outbox} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  topic               VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  job_id              BIGINT UNSIGNED NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  object_id           BIGINT UNSIGNED NULL,
  payload_json        TEXT NOT NULL,
  status              VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  attempts            INT UNSIGNED NOT NULL DEFAULT 0,
  available_at        DATETIME NOT NULL,
  locked_until        DATETIME NULL,
  last_error_code     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at          DATETIME NOT NULL,
  done_at             DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY event_uuid (event_uuid),
  KEY status_available (status, available_at),
  KEY job_object (job_id, object_type, object_id)
) ENGINE=InnoDB {$collate}",
            "CREATE TABLE IF NOT EXISTS {$t->eventLog} (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at          DATETIME NOT NULL,
  level               VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code                VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  job_id              BIGINT UNSIGNED NULL,
  object_type         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  object_id           BIGINT UNSIGNED NULL,
  adapter_key         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  duration_ms         INT UNSIGNED NULL,
  context_json        TEXT NULL,
  PRIMARY KEY (id),
  KEY created (created_at, id),
  KEY level_id (level, id)
) ENGINE=InnoDB {$collate}",
        ];
    }
}
