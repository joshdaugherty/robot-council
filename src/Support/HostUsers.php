<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobotCouncil\Models\GithubIdentity;
use RuntimeException;

/**
 * Reads and writes the records behind a developer: the host application's user row, and the
 * package's own GitHub identity for it. The package owns no users table, and takes the model from
 * the host's `auth.providers.users.model` configuration.
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
     * @throws RuntimeException When the configured model is not an authenticatable Eloquent model.
     */
    public function modelClass(): string
    {
        $model = $this->config->get('auth.providers.users.model');

        // Refuse before any row is read or written, rather than failing after creating a user
        if (! \is_string($model) || ! is_subclass_of($model, Model::class) || ! is_a($model, Authenticatable::class, true)) {
            throw new RuntimeException('Set `auth.providers.users.model` to an Eloquent model that implements Authenticatable for robot-council.');
        }

        return $model;
    }

    /**
     * Find the package's identity for a GitHub account.
     *
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @return GithubIdentity|null The identity, or null when the account has never signed in.
     */
    public function findIdentity(int $githubId): ?GithubIdentity
    {
        return GithubIdentity::query()->where('github_id', $githubId)->first();
    }

    /**
     * Find the host user an identity points at.
     *
     * @param  GithubIdentity  $identity  The identity to resolve.
     * @return Model|null The user, or null when the host has deleted or hidden the row.
     */
    public function findUserFor(GithubIdentity $identity): ?Model
    {
        $model = $this->modelClass();

        return $model::query()->whereKey($identity->user_id)->first();
    }

    /**
     * Find a host user by email address, without regard to case.
     *
     * @param  string  $email  The email address to look for.
     * @return Model|null The matching user, or null when no user holds that address.
     */
    public function findByEmail(string $email): ?Model
    {
        $model = $this->modelClass();

        // Compare case-insensitively, because collations differ by host database
        return $model::query()
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->first();
    }

    /**
     * Create a host user for a developer signing in for the first time.
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
     * Record, or refresh, the GitHub identity for a host user.
     *
     * @param  Model  $user  The host user the account belongs to.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @param  string  $login  The account's login.
     * @param  string|null  $avatarUrl  The account's avatar URL, when GitHub exposes one.
     * @return GithubIdentity The saved identity.
     */
    public function recordIdentity(Model $user, int $githubId, string $login, ?string $avatarUrl): GithubIdentity
    {
        return GithubIdentity::query()->updateOrCreate(
            ['github_id' => $githubId],
            [
                'user_id' => $user->getKey(),
                'github_login' => $login,
                'avatar_url' => $avatarUrl,
            ]
        );
    }

    /**
     * Read the GitHub user ID recorded for a signed-in user.
     *
     * @param  Authenticatable  $user  The user resolved by the host application's guard.
     * @return int|null The account's GitHub user ID, or null when the user has no identity.
     */
    public function githubId(Authenticatable $user): ?int
    {
        $identity = GithubIdentity::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        return $identity?->github_id;
    }
}
