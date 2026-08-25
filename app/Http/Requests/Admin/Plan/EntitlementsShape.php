<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Plan;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * Shape check for an `entitlements` payload once `max_audio_quality` (the one
 * key with a rule of its own) is set aside: every remaining key must be
 * `snake_case` and every remaining value must be a boolean. This is what lets
 * the admin panel introduce a brand-new capability key with no backend
 * change — {@see StorePlanRequest} and {@see UpdatePlanRequest} both defer to
 * this instead of hard-coding a rule per known key.
 */
final class EntitlementsShape
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  array<string, mixed>  $entitlements
     */
    public static function validate(ValidatorContract $validator, array $entitlements): void
    {
        foreach ($entitlements as $key => $value) {
            if ($key === 'max_audio_quality') {
                continue;
            }

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $validator->errors()->add(
                    'entitlements',
                    "Entitlement key \"{$key}\" must be lowercase snake_case (letters, digits, underscores).",
                );

                continue;
            }

            if (! is_bool($value)) {
                $validator->errors()->add("entitlements.{$key}", 'Must be true or false.');
            }
        }
    }
}
