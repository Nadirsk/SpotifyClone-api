<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\SubscriptionPlan;
use App\Services\Billing\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $purchasable = array_map(
            static fn (SubscriptionPlan $plan): string => $plan->value,
            SubscriptionPlan::purchasable(),
        );

        return [
            // `free` is excluded here rather than caught in the service, so the
            // client gets a field-level 422 instead of a domain 422.
            'plan' => ['required', 'string', Rule::in($purchasable)],
            /*
             | Optional: omitted means "bill me in my country's currency", which
             | `SubscriptionController` resolves from the profile. Accepting it
             | explicitly is what lets the plans page's currency switcher buy in
             | the currency the listener is looking at.
             */
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(app(PlanCatalog::class)->currencies())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'plan.in' => 'Choose one of the Standard, Platinum or Student plans.',
        ];
    }

    public function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::from((string) $this->string('plan'));
    }

    public function currency(): ?string
    {
        $currency = $this->string('currency')->toString();

        return $currency === '' ? null : strtoupper($currency);
    }
}
