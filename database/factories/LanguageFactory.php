<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    /**
     * `code` is unique, so the factory draws from a fixed pool rather than
     * inventing codes that would collide within a single test.
     *
     * @var array<string, string>
     */
    private const POOL = [
        'English' => 'en',
        'Hindi' => 'hi',
        'Punjabi' => 'pa',
        'Tamil' => 'ta',
        'Telugu' => 'te',
        'Malayalam' => 'ml',
        'Spanish' => 'es',
        'Japanese' => 'ja',
        'Korean' => 'ko',
        'French' => 'fr',
        'German' => 'de',
        'Portuguese' => 'pt',
        'Italian' => 'it',
        'Dutch' => 'nl',
        'Swedish' => 'sv',
        'Turkish' => 'tr',
        'Arabic' => 'ar',
        'Russian' => 'ru',
        'Bengali' => 'bn',
        'Marathi' => 'mr',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = fake()->unique()->randomElement(array_keys(self::POOL));

        return [
            'name' => $name,
            'code' => self::POOL[$name],
        ];
    }

    /**
     * A language with a known ISO code, so a test can filter on `?language=en`.
     */
    public function code(string $code): static
    {
        $name = array_search($code, self::POOL, true);

        return $this->state(fn (array $attributes): array => [
            'name' => $name === false ? strtoupper($code) : $name,
            'code' => $code,
        ]);
    }
}
