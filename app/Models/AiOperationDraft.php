<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int|null $executed_by
 * @property string $status
 * @property string $input_text
 * @property array|null $minimax_request
 * @property array|null $minimax_response
 * @property array<int, array<string, mixed>> $operations
 * @property array<int, string>|null $warnings
 * @property Carbon|null $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiOperationDraft extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_EXECUTED = 'executed';

    protected $fillable = [
        'organization_id',
        'user_id',
        'executed_by',
        'status',
        'input_text',
        'minimax_request',
        'minimax_response',
        'operations',
        'warnings',
        'executed_at',
    ];

    protected $casts = [
        'minimax_request' => 'array',
        'minimax_response' => 'array',
        'operations' => 'array',
        'warnings' => 'array',
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
