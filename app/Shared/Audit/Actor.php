<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use InvalidArgumentException;

/**
 * The actor of an audited change, as recorded in audit_log (ADR-0023
 * Decisions 1 and 2). Only the named constructors build one, and each
 * builds only a shape the audit_log CHECK constraints accept:
 *
 * - user(): a person, with their user id, an optional impersonator user id
 *   and an optional client IP. No label.
 * - console(): provenance, not attribution. The label is the command name;
 *   there is no user id, impersonator or IP, and nothing taken from command
 *   input identifies whoever ran it.
 * - system(): a scheduled job or queue worker, labelled with its class.
 *
 * Constructing an Actor authenticates and authorises nothing. A user actor
 * claims the id it is given: the caller must build it only from an
 * authenticated identity, and no such identity exists yet.
 */
final readonly class Actor
{
    /** actor_label is VARCHAR(191). */
    private const MAX_LABEL_LENGTH = 191;

    private function __construct(
        public ActorType $type,
        public ?int $userId,
        public ?int $impersonatorUserId,
        public ?string $label,
        public ?string $ip,
    ) {}

    /**
     * @param  int  $userId  The authenticated user's id.
     * @param  int|null  $impersonatorUserId  The staff user acting as $userId, if any.
     * @param  string|null  $ip  The client's IPv4 or IPv6 address, if known.
     */
    public static function user(int $userId, ?int $impersonatorUserId = null, ?string $ip = null): self
    {
        self::assertUserId($userId, 'user id');

        if ($impersonatorUserId !== null) {
            self::assertUserId($impersonatorUserId, 'impersonator user id');
        }

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("An actor's IP must be an IPv4 or IPv6 address, got \"{$ip}\".");
        }

        return new self(ActorType::User, $userId, $impersonatorUserId, null, $ip);
    }

    /**
     * @param  string  $command  The command's name, for example vendors:create.
     */
    public static function console(string $command): self
    {
        return new self(ActorType::Console, null, null, self::label($command, 'console'), null);
    }

    /**
     * @param  string  $job  The job or worker class.
     */
    public static function system(string $job): self
    {
        return new self(ActorType::System, null, null, self::label($job, 'system'), null);
    }

    private static function assertUserId(int $id, string $name): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException("An actor's {$name} must be a positive id, got {$id}.");
        }
    }

    private static function label(string $label, string $type): string
    {
        if (trim($label) === '' || trim($label) !== $label) {
            throw new InvalidArgumentException("A {$type} actor needs a label without surrounding whitespace, got \"{$label}\".");
        }

        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidArgumentException("A {$type} actor's label is longer than ".self::MAX_LABEL_LENGTH.' characters.');
        }

        return $label;
    }
}
