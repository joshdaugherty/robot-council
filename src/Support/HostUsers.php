<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Reads and writes the host application's user records. The package owns no users table: it takes
 * the model from the host's `auth.providers.users.model` configuration and the columns that
 * `robot-council:install` adds to that table.
 */
final class HostUsers
{
    /**
     * @param  Repository  $config  The host application's configuration repository.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Resolve the host application's user model.
     *
     * @return class-string<Model> The Eloquent model behind the `users` authentication provider.
     *
     * @throws RuntimeException When the configured model is not an Eloquent model class.
     */
    public function modelClass(): string
    {
        $model = $this->config->get('auth.providers.users.model');

        // Refuse anything that is not an Eloquent model, rather than failing later on a query
        if (! \is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new RuntimeException('Set `auth.providers.users.model` to an Eloquent model class for robot-council.');
        }

        return $model;
    }

    /**
     * Find the host user enrolled for a GitHub account.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return Model|null The matching user, or null when the account has never signed in.
     */
    public function findByGithubId(int $githubId): ?Model
    {
        $model = $this->modelClass();

        return $model::query()->where('github_id', $githubId)->first();
    }

    /**
     * Find a host user by email address, to detect an account that is not ours to claim.
     *
     * @param  string  $email  The email address to look for.
     * @return Model|null The matching user, or null when no user holds that address.
     */
    public function findByEmail(string $email): ?Model
    {
        $model = $this->modelClass();

        return $model::query()->where('email', $email)->first();
    }

    /**
     * Create a host user from GitHub's account details.
     *
     * @param  array<string, mixed>  $attributes  The columns to set on the new user.
     * @return Model The saved user.
     */
    public function create(array $attributes): Model
    {
        $model = $this->modelClass();

        $user = new $model;

        // Force-fill, because the host model's `$fillable` is not the package's to assume
        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * Read the GitHub user ID recorded on a signed-in user.
     *
     * @param  Authenticatable  $user  The user resolved by the host application's guard.
     * @return int|null The account's GitHub user ID, or null when the column is absent or unset.
     */
    public function githubId(Authenticatable $user): ?int
    {
        // Only an Eloquent model carries the column `robot-council:install` adds
        if (! $user instanceof Model) {
            return null;
        }

        $githubId = $user->getAttribute('github_id');

        if (\is_int($githubId)) {
            return $githubId;
        }

        // Accept a numeric string, which is how some drivers return a big integer column
        if (\is_string($githubId) && ctype_digit($githubId)) {
            return (int) $githubId;
        }

        return null;
    }
}
