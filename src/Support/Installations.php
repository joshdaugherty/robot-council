<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;

/**
 * Creating, revoking, and re-scoping installations.
 *
 * An installation credential can do one thing: start and renew agent sessions. The abilities a
 * developer approved ride on the session tokens instead, so a stolen installation credential
 * cannot itself create a task, take a lock, or post to the feed.
 */
final class Installations
{
    /**
     * The name Sanctum records against an installation's credential.
     */
    public const string CREDENTIAL_NAME = 'robot-council installation';

    /**
     * @param  Credentials  $credentials  The configured lifetimes.
     */
    public function __construct(private readonly Credentials $credentials) {}

    /**
     * Create the installation an approved device code stands for, and its credential.
     *
     * Both happen in one transaction, so nothing can leave an installation with no way to reach it
     * or a credential belonging to no installation.
     *
     * @param  DeviceCode  $code  The approved and already-claimed request.
     * @return IssuedCredential<Installation> The installation and its plaintext credential.
     */
    public function createFrom(DeviceCode $code): IssuedCredential
    {
        // Re-checked rather than trusted, although `DeviceCodes::issue()` has already bounded these.
        // `createFrom()` takes a model, and a caller can hand it one it built itself rather than one
        // this package wrote -- so the copy forward is its own entry point into every column the
        // create below writes. **All five of them, not the two that are obviously text**: the
        // decider's key lands in `user_id` AND `approved_by`, both `varchar(64)`, and the IP in a
        // `varchar(45)`. Checking a subset would leave exactly the "one call, three outcomes" this
        // guard exists to close.
        MachineIdentity::ensure($code->harness, $code->machine_label);

        // Through `HostKey`, which is where the 64-character bound on a host user key lives, rather
        // than a length test written again here
        HostKey::from($code->decided_by);

        if ($code->requested_ip !== null && mb_strlen($code->requested_ip) > DeviceCodes::MAX_REQUESTED_IP) {
            throw new InvalidArgumentException(sprintf(
                'A requested IP is limited to %d characters, and this one is %d.',
                DeviceCodes::MAX_REQUESTED_IP,
                mb_strlen($code->requested_ip)
            ));
        }

        return DB::transaction(function () use ($code): IssuedCredential {
            $installation = Installation::query()->create([
                'user_id' => $code->decided_by,
                'harness' => $code->harness,
                'machine_label' => $code->machine_label,
                'granted_abilities' => $code->granted_abilities ?? [],
                'approved_by' => $code->decided_by,
                'requested_ip' => $code->requested_ip,
                'expires_at' => $this->credentials->installationExpiry(),
            ]);

            $token = $installation->createToken(
                self::CREDENTIAL_NAME,
                [Ability::SessionsStart->value],
                $installation->expires_at
            );

            return new IssuedCredential($installation, $token->plainTextToken, [Ability::SessionsStart->value]);
        });
    }

    /**
     * Revoke an installation: its credential and every session token it issued stop working on the
     * next request.
     *
     * The sessions themselves are left as they are. Nothing can reach them, because renewing one
     * needs the installation credential and every request re-reads `revoked_at`.
     *
     * @param  Installation  $installation  The installation to revoke.
     * @return int How many tokens were deleted.
     */
    public function revoke(Installation $installation): int
    {
        return DB::transaction(function () use ($installation): int {
            // The installation's own row first, then the tokens. That is the package's lock order,
            // and `AgentSessions::renew()` already held this row while reaching for the same
            // tokens -- so taking them the other way round here was a deadlock between revoking an
            // installation and one of its sessions renewing.
            $installation->forceFill(['revoked_at' => Carbon::now()])->save();

            $deleted = Tokens::deleted($installation->tokens()->delete());

            foreach ($this->sessionsOf($installation) as $session) {
                $deleted += Tokens::deleted($session->tokens()->delete());
            }

            return $deleted;
        });
    }

    /**
     * Add or remove one ability on an installation, and on the session tokens already in flight.
     *
     * Rewriting the live tokens is the point: a session token lives for an hour, so leaving them
     * alone would let a revoked ability keep working until every process happened to renew.
     *
     * @param  Installation  $installation  The installation to re-scope.
     * @param  Ability  $ability  The ability to add or remove.
     * @param  bool  $granted  True to add it, false to remove it.
     * @return int How many live session tokens were rewritten.
     */
    public function setAbility(Installation $installation, Ability $ability, bool $granted): int
    {
        return DB::transaction(function () use ($installation, $ability, $granted): int {
            // Held for the length of the transaction, so a session starting concurrently waits
            // rather than minting a token from the abilities as they were a moment ago
            $installation = Installation::query()
                ->whereKey($installation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $abilities = $installation->abilities();

            $abilities = $granted
                ? array_values(array_unique([...$abilities, $ability->value]))
                : array_values(array_filter($abilities, static fn (string $held): bool => $held !== $ability->value));

            $installation->forceFill(['granted_abilities' => $abilities])->save();

            $rewritten = 0;

            foreach ($this->sessionsOf($installation) as $session) {
                foreach ($session->tokens()->get() as $token) {
                    $token->forceFill(['abilities' => $abilities])->save();

                    $rewritten++;
                }
            }

            return $rewritten;
        });
    }

    /**
     * The sessions an installation has started.
     *
     * @param  Installation  $installation  The installation to read.
     * @return Collection<int, AgentSession> Its sessions.
     */
    private function sessionsOf(Installation $installation): Collection
    {
        return $installation->sessions()->get();
    }
}
