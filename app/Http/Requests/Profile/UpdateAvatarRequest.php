<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // `image` and an explicit mime whitelist together: `image` alone
            // would also let SVG through, which can carry script.
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function avatar(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('avatar');

        return $file;
    }
}
