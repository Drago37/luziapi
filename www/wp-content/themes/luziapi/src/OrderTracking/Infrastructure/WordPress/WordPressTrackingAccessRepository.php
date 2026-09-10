<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use wpdb;

final readonly class WordPressTrackingAccessRepository implements TrackingAccessRepository
{
    public function __construct(
        private wpdb $database,
        private OrderTrackingSchemaManager $schema,
        private LoggerInterface $logger,
    ) {
    }

    public function allowAttempt(
        string $scope,
        string $subjectFingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $at,
    ): bool {
        $table = $this->schema->limitsTableName();
        $this->database->query('START TRANSACTION');
        try {
            $row = $this->database->get_row($this->database->prepare(
                "SELECT window_started_at, attempts FROM {$table} WHERE scope = %s AND subject_hash = %s FOR UPDATE",
                $scope,
                $subjectFingerprint,
            ), ARRAY_A);
            // Distinguer « aucune tentative » d'une erreur de lecture : sur erreur
            // DB on échoue fermé (throw) plutôt que de réinitialiser le compteur.
            if (null === $row && '' !== (string) $this->database->last_error) {
                throw new RuntimeException('Unable to read order tracking rate limit: ' . $this->database->last_error);
            }
            $windowCutoff = $at->modify('-' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s');
            if (! is_array($row) || (string) $row['window_started_at'] <= $windowCutoff) {
                $saved = $this->database->replace($table, [
                    'scope' => $scope,
                    'subject_hash' => $subjectFingerprint,
                    'window_started_at' => $at->format('Y-m-d H:i:s'),
                    'attempts' => 1,
                ], ['%s', '%s', '%s', '%d']);
                if (false === $saved) {
                    throw new RuntimeException('Unable to persist order tracking rate limit.');
                }
                $this->database->query('COMMIT');

                return true;
            }

            if ((int) $row['attempts'] >= max(1, $limit)) {
                $this->database->query('COMMIT');

                return false;
            }

            $updated = $this->database->query($this->database->prepare(
                "UPDATE {$table} SET attempts = attempts + 1 WHERE scope = %s AND subject_hash = %s",
                $scope,
                $subjectFingerprint,
            ));
            if (false === $updated) {
                throw new RuntimeException('Unable to update order tracking rate limit.');
            }
            $this->database->query('COMMIT');

            return true;
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');
            $this->logger->error('Suivi commande : contrôle de débit indisponible (fail-closed).', [
                'scope' => $scope,
            ]);
            throw $exception;
        }
    }

    public function issueMagicLink(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->replaceTokenRow(
            $this->schema->grantsTableName(),
            $tokenFingerprint,
            $orderIds,
            $expiresAt,
            $createdAt,
        );
    }

    public function consumeMagicLink(string $tokenFingerprint, DateTimeImmutable $at): ?array
    {
        $table = $this->schema->grantsTableName();
        $this->database->query('START TRANSACTION');
        try {
            $row = $this->database->get_row($this->database->prepare(
                "SELECT order_ids, expires_at, consumed_at FROM {$table} WHERE token_hash = %s FOR UPDATE",
                $tokenFingerprint,
            ), ARRAY_A);
            if (! is_array($row)
                || null !== $row['consumed_at']
                || (string) $row['expires_at'] < $at->format('Y-m-d H:i:s')) {
                $this->database->query('COMMIT');

                return null;
            }

            $updated = $this->database->update(
                $table,
                ['consumed_at' => $at->format('Y-m-d H:i:s')],
                ['token_hash' => $tokenFingerprint, 'consumed_at' => null],
                ['%s'],
                ['%s', '%s'],
            );
            if (false === $updated || 1 !== $updated) {
                $this->database->query('ROLLBACK');

                return null;
            }
            $this->database->query('COMMIT');

            return $this->decodeOrderIds((string) $row['order_ids']);
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');
            throw $exception;
        }
    }

    public function createSession(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->replaceTokenRow(
            $this->schema->sessionsTableName(),
            $tokenFingerprint,
            $orderIds,
            $expiresAt,
            $createdAt,
        );
    }

    public function sessionOrderIds(string $tokenFingerprint, DateTimeImmutable $at): ?array
    {
        $table = $this->schema->sessionsTableName();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT order_ids FROM {$table} WHERE token_hash = %s AND expires_at >= %s",
            $tokenFingerprint,
            $at->format('Y-m-d H:i:s'),
        ), ARRAY_A);

        return is_array($row) ? $this->decodeOrderIds((string) $row['order_ids']) : null;
    }

    public function revokeSession(string $tokenFingerprint): void
    {
        $deleted = $this->database->delete($this->schema->sessionsTableName(), ['token_hash' => $tokenFingerprint], ['%s']);
        if (false === $deleted) {
            // La déconnexion vide le cookie côté client, mais la session serveur
            // survit jusqu'à expiration : le signaler pour ne pas le laisser filer.
            $this->logger->error('Suivi commande : révocation de session échouée (DELETE).');
        }
    }

    public function purgeExpired(DateTimeImmutable $at): void
    {
        $now = $at->format('Y-m-d H:i:s');
        $ok = false !== $this->database->query($this->database->prepare(
            'DELETE FROM ' . $this->schema->grantsTableName() . ' WHERE expires_at < %s',
            $now,
        ));
        $ok = (false !== $this->database->query($this->database->prepare(
            'DELETE FROM ' . $this->schema->sessionsTableName() . ' WHERE expires_at < %s',
            $now,
        ))) && $ok;
        $ok = (false !== $this->database->query($this->database->prepare(
            'DELETE FROM ' . $this->schema->limitsTableName() . ' WHERE window_started_at < %s',
            $at->modify('-1 day')->format('Y-m-d H:i:s'),
        ))) && $ok;
        if (! $ok) {
            // Nettoyage best-effort : on ne casse rien, mais on signale pour que
            // les tables de suivi ne grossissent pas sans qu'on le sache.
            $this->logger->warning('Suivi commande : purge des jetons/sessions expirés incomplète.');
        }
    }

    /** @param non-empty-list<int> $orderIds */
    private function replaceTokenRow(
        string $table,
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $encoded = wp_json_encode(array_values(array_unique($orderIds)));
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode allowed order identifiers.');
        }
        $saved = $this->database->replace($table, [
            'token_hash' => $tokenFingerprint,
            'order_ids' => $encoded,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ], ['%s', '%s', '%s', '%s']);
        if (false === $saved) {
            throw new RuntimeException('Unable to persist order tracking access.');
        }
    }

    /** @return non-empty-list<int>|null */
    private function decodeOrderIds(string $encoded): ?array
    {
        $decoded = json_decode($encoded, true);
        if (! is_array($decoded)) {
            return null;
        }
        $orderIds = array_values(array_unique(array_filter(
            array_map('intval', $decoded),
            static fn (int $orderId): bool => $orderId > 0,
        )));

        return [] === $orderIds ? null : $orderIds;
    }
}
