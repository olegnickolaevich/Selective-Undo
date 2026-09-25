<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Rest;

use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Infrastructure\Database\StorageUnavailable;
use SelectiveUndo\Infrastructure\Diagnostics\EventLog;

/**
 * Uniform error format: { code, message, data: { status, request_id, details? } }.
 * Messages never contain SQL, content or secrets.
 */
final class ErrorMapper
{
    public function __construct(
        private readonly EventLog $log,
        private readonly string $requestUuid,
    ) {
    }

    /**
     * @param callable(): mixed $fn
     */
    public function run(callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            $result = $fn();

            return $result instanceof \WP_REST_Response ? $result : new \WP_REST_Response($result, 200);
        } catch (DomainError $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (StorageUnavailable $e) {
            $this->log->exception('rest_storage_unavailable', $e);

            return $this->error('su_storage_unavailable', __('The history storage is temporarily unavailable.', 'selective-undo'), 503, ['reason' => $e->reasonCode]);
        } catch (\Throwable $e) {
            $this->log->exception('rest_internal_error', $e);

            return $this->error('su_internal_error', __('Something went wrong. The details were written to the Selective Undo diagnostics log.', 'selective-undo'), 500);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    public function error(string $code, string $message, int $status, array $details = []): \WP_Error
    {
        $data = ['status' => $status, 'request_id' => $this->requestUuid];

        if ($details !== []) {
            $data['details'] = $details;
        }

        return new \WP_Error($code, $message, $data);
    }
}
