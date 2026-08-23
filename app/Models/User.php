<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser as PasskeyUserContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\PasskeyAuthenticatable;

/**
 * @property CarbonImmutable|null $created_at
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property int $id
 * @property string $name
 * @property string|null $remember_token
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_secret
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Session> $sessions
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class User extends Model implements AuthenticatableContract, AuthorizableContract, PasskeyUserContract
{
    use Authenticatable;
    use Authorizable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Session, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
