<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlendMemberRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlendMember extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'blend_id',
        'user_id',
        'role',
        'joined_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => BlendMemberRole::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Blend, $this> */
    public function blend(): BelongsTo
    {
        return $this->belongsTo(Blend::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
