<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row = one settings field.
 *
 * See the create_app_settings_table migration for why this is a key-value
 * table and why rhu_id uses 0 rather than NULL for "applies to every RHU".
 *
 * Read and written through App\Support\AppSettings -- callers should not query
 * this model directly, so that typing and defaults stay in one place.
 */
class AppSetting extends Model
{
    use HasFactory;

    public const GROUP_FACILITY = 'facility';
    public const GROUP_NOTIFICATIONS = 'notifications';
    public const GROUP_SECURITY = 'security';

    /** rhu_id value meaning "not facility-specific". */
    public const SHARED_RHU_ID = 0;

    protected $fillable = [
        'group',
        'rhu_id',
        'key',
        'value',
        'type',
        'updated_by',
    ];

    protected $casts = [
        'rhu_id' => 'integer',
        'updated_by' => 'integer',
    ];

    /**
     * The stored string turned back into the type it was saved as.
     *
     * Null survives as null on purpose: "never set" is a state the UI has to
     * be able to show honestly, and is not the same as 0 or "".
     */
    public function typedValue(): string|int|bool|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'int' => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $this->value,
        };
    }
}
