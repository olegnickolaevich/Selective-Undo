<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/** Deadlock or lock wait timeout: the server rolled the transaction back. */
final class RetryableDbError extends DbError
{
}
