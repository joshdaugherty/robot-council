<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A token as it exists for the one response that carries it: the row it was issued to, beside the
 * plaintext nothing stores and nothing reads back. Sanctum keeps only the token's SHA-256.
 *
 * @template TOwner of Model
 */
final class IssuedCredential
{
    /**
     * @param  TOwner  $owner  The installation or agent session the token authenticates as.
     * @param  string  $plainTextToken  The bearer token, returned once and never again.
     */
    public function __construct(
        public readonly Model $owner,
        public readonly string $plainTextToken
    ) {}
}
