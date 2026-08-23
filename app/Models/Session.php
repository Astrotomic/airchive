<?php

namespace App\Models;

use App\Models\Builders\SessionBuilder;
use App\ValueObjects\IpInfo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\HasBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Session as SessionFacade;
use UAParser\Parser;
use UAParser\Result\Client;

/**
 * @property string $id
 * @property string|null $ip_address
 * @property CarbonImmutable $last_activity
 * @property string $payload
 * @property string|null $user_agent
 * @property int|null $user_id
 * @property-read IpInfo|null $ip_info
 * @property-read Client $parsed_user_agent
 * @property-read User|null $user
 *
 * @method static SessionBuilder<static>|Session revokeOthers(string $currentSessionId)
 * @method static SessionBuilder<static>|Session revoke(string $sessionId)
 * @method static SessionBuilder<static> query()
 */
#[UseEloquentBuilder(SessionBuilder::class)]
class Session extends Model
{
    /** @use HasBuilder<SessionBuilder> */
    use HasBuilder;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
        ];
    }

    /** @return Attribute<Client, never> */
    protected function parsedUserAgent(): Attribute
    {
        return Attribute::get(
            fn (): Client => Parser::create()->parse((string) $this->user_agent),
        )->shouldCache();
    }

    /** @return Attribute<IpInfo|null, never> */
    protected function ipInfo(): Attribute
    {
        return Attribute::get(
            fn (): ?IpInfo => filled($this->ip_address)
                ? IpInfo::fetch((string) $this->ip_address)
                : null,
        )->shouldCache();
    }

    public function isCurrent(): bool
    {
        return $this->getKey() === SessionFacade::getId();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
