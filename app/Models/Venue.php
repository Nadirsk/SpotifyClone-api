<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'city',
        'address',
    ];

    /** @return HasMany<Concert, $this> */
    public function concerts(): HasMany
    {
        return $this->hasMany(Concert::class);
    }
}
