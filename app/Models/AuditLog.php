<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Action constants
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_TOKEN_CREATED = 'token_created';
    public const ACTION_TOKEN_REVOKED = 'token_revoked';
    public const ACTION_TRANSLATION_UPLOAD = 'translation_upload';
    public const ACTION_TRANSLATION_DOWNLOAD = 'translation_download';
    public const ACTION_TRANSLATION_DELETE = 'translation_delete';
    public const ACTION_USER_BANNED = 'user_banned';
    public const ACTION_USER_UNBANNED = 'user_unbanned';
    public const ACTION_REPORT_RESOLVED = 'report_resolved';
    public const ACTION_ADMIN_ACTION = 'admin_action';
    public const ACTION_DEVICE_LINKED = 'device_linked';

    /**
     * Log an action with request context
     */
    public static function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?Request $request = null
    ): self {
        // Use current request if not provided
        $request = $request ?? request();

        return self::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Log a login event
     */
    public static function logLogin(int $userId, string $provider, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_LOGIN,
            $userId,
            'User',
            $userId,
            ['provider' => $provider],
            $request
        );
    }

    /**
     * Log a logout event
     */
    public static function logLogout(?int $userId = null, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_LOGOUT,
            $userId,
            'User',
            $userId,
            null,
            $request
        );
    }

    /**
     * Log API token creation
     */
    public static function logTokenCreated(int $userId, string $tokenName, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_TOKEN_CREATED,
            $userId,
            'ApiToken',
            null,
            ['token_name' => $tokenName],
            $request
        );
    }

    /**
     * Log API token revocation
     */
    public static function logTokenRevoked(int $userId, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_TOKEN_REVOKED,
            $userId,
            'ApiToken',
            null,
            null,
            $request
        );
    }

    /**
     * Log translation upload
     */
    public static function logTranslationUpload(int $userId, int $translationId, array $details, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_TRANSLATION_UPLOAD,
            $userId,
            'Translation',
            $translationId,
            $details,
            $request
        );
    }

    /**
     * Log translation download (API)
     */
    public static function logTranslationDownload(int $translationId, ?int $userId = null, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_TRANSLATION_DOWNLOAD,
            $userId,
            'Translation',
            $translationId,
            null,
            $request
        );
    }

    /**
     * Log user ban action
     */
    public static function logUserBanned(int $targetUserId, int $adminUserId, ?string $reason, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_USER_BANNED,
            $adminUserId,
            'User',
            $targetUserId,
            ['reason' => $reason],
            $request
        );
    }

    /**
     * Log user unban action
     */
    public static function logUserUnbanned(int $targetUserId, int $adminUserId, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_USER_UNBANNED,
            $adminUserId,
            'User',
            $targetUserId,
            null,
            $request
        );
    }

    /**
     * Log device code link
     */
    public static function logDeviceLinked(int $userId, string $userCode, ?Request $request = null): self
    {
        return self::log(
            self::ACTION_DEVICE_LINKED,
            $userId,
            'DeviceCode',
            null,
            ['user_code' => $userCode],
            $request
        );
    }

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
