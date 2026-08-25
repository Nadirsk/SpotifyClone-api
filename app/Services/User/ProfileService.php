<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Contracts\Repositories\UserRepository;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The authenticated user's own profile (05_API_SPECIFICATION §14).
 */
final class ProfileService
{
    /**
     * Vultr Object Storage (S3-compatible) — see config/filesystems.php.
     * Local disk would lose every avatar on a redeploy or a second app server.
     */
    private const AVATAR_DISK = 'vultr';

    private const AVATAR_DIRECTORY = 'avatars';

    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function show(User $user): User
    {
        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return $this->users->update($user, $attributes);
    }

    public function updateAvatar(User $user, UploadedFile $avatar): User
    {
        // S3-compatible disks default to private objects — 'visibility' has
        // to be set explicitly, unlike the local 'public' disk which is
        // public by virtue of the storage:link symlink it sits behind.
        $path = $avatar->store(self::AVATAR_DIRECTORY, [
            'disk' => self::AVATAR_DISK,
            'visibility' => 'public',
        ]);

        if (! is_string($path)) {
            throw new RuntimeException('Failed to store the uploaded avatar.');
        }

        $previous = $user->avatar;

        $updated = $this->users->update($user, [
            'avatar' => Storage::disk(self::AVATAR_DISK)->url($path),
        ]);

        // Only after the new URL is persisted, so a failed write never leaves
        // the user with a column pointing at a deleted file.
        $this->deleteStoredAvatar($previous);

        return $updated;
    }

    public function delete(User $user): void
    {
        /*
         | Revoked explicitly rather than relying on the soft-delete scope to
         | make the tokens unusable: if the account is ever restored, the old
         | bearer tokens must not come back to life with it.
         */
        $user->tokens()->delete();

        $this->users->delete($user);
    }

    /**
     * The avatar column also holds remote URLs copied from an OAuth provider,
     * which are not ours to delete — only files this app wrote are removed.
     */
    private function deleteStoredAvatar(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $disk = Storage::disk(self::AVATAR_DISK);
        $prefix = $disk->url(self::AVATAR_DIRECTORY.'/');

        if (! str_starts_with($url, $prefix)) {
            return;
        }

        $disk->delete(self::AVATAR_DIRECTORY.'/'.basename($url));
    }
}
