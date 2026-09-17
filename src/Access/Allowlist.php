<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Contracts\Config\Repository;

/**
 * Decides which GitHub accounts may use the service, from the `robot-council.access` lists. It
 * reads configuration on every call, so removing an ID takes effect on the next request — unless
 * the host application caches its configuration, in which case it takes effect when the host
 * re-runs `php artisan config:cache`.
 */
final class Allowlist
{
    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Determine whether a GitHub account may sign in at all.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return bool True when the ID is listed as a developer or an admin.
     */
    public function admits(int $githubId): bool
    {
        return \in_array($githubId, $this->developers(), true) || $this->isAdmin($githubId);
    }

    /**
     * Determine whether a GitHub account holds admin rights.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return bool True when the ID is listed as an admin.
     */
    public function isAdmin(int $githubId): bool
    {
        return \in_array($githubId, $this->admins(), true);
    }

    /**
     * The GitHub user IDs listed as developers.
     *
     * @return list<int> The configured developer IDs, without duplicates.
     */
    public function developers(): array
    {
        return $this->ids('robot-council.access.developers');
    }

    /**
     * The GitHub user IDs listed as admins.
     *
     * @return list<int> The configured admin IDs, without duplicates.
     */
    public function admins(): array
    {
        return $this->ids('robot-council.access.admins');
    }

    /**
     * Read one access list, accepting a comma-separated string or an array.
     *
     * @param  string  $key  The configuration key holding the list.
     * @return list<int> The numeric IDs in that list, without duplicates.
     */
    private function ids(string $key): array
    {
        $configured = $this->config->get($key, []);

        // Refuse anything else: `ROBOT_COUNCIL_ADMINS=true` becomes boolean true, whose string
        // form is `1`, which would otherwise admit GitHub user ID 1
        if (! \is_array($configured) && ! \is_string($configured)) {
            return [];
        }

        // Split an environment string into entries, and take an array as it stands
        $entries = \is_array($configured) ? $configured : explode(',', $configured);

        // Keep only the entries that are whole numbers, since GitHub user IDs are integers
        $ids = [];

        foreach ($entries as $entry) {
            if (! \is_scalar($entry)) {
                continue;
            }

            $candidate = trim((string) $entry);

            if ($candidate === '' || ctype_digit($candidate) === false) {
                continue;
            }

            $ids[] = (int) $candidate;
        }

        return array_values(array_unique($ids));
    }
}
