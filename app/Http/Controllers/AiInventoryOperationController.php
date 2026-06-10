<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiOperationDraft;
use App\Services\AiInventoryOperationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class AiInventoryOperationController extends Controller
{
    public function __construct(
        private readonly AiInventoryOperationService $service
    ) {}

    public function draft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        try {
            $draft = $this->service->createDraft($request->user(), $validated['instruction']);
        } catch (InvalidArgumentException|RuntimeException|RequestException $exception) {
            throw ValidationException::withMessages([
                'instruction' => $this->messageFor($exception),
            ]);
        }

        return $this->draftResponse($draft, 201);
    }

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_id' => ['required', 'integer'],
        ]);

        $draft = $this->service->executeDraft($request->user(), (int) $validated['draft_id']);

        return $this->draftResponse($draft);
    }

    private function draftResponse(AiOperationDraft $draft, int $status = 200): JsonResponse
    {
        return response()->json([
            'draft' => [
                'id' => $draft->id,
                'status' => $draft->status,
                'operations' => $this->operationsForResponse($draft->operations),
                'warnings' => $draft->warnings ?? [],
                'executed_at' => $draft->executed_at?->toISOString(),
            ],
        ], $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<int, array<string, mixed>>
     */
    private function operationsForResponse(array $operations): array
    {
        return array_map(function (array $operation): array {
            $operation['changes'] = array_map(function (array $change): array {
                if (($change['unit'] ?? null) === 'USD') {
                    $change['old_value'] = (float) $change['old_value'];
                    $change['new_value'] = (float) $change['new_value'];
                } elseif (($change['unit'] ?? null) === 'units') {
                    $change['old_value'] = (int) $change['old_value'];
                    $change['new_value'] = (int) $change['new_value'];
                }

                return $change;
            }, $operation['changes'] ?? []);

            return $operation;
        }, $operations);
    }

    private function messageFor(\Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return 'MiniMax API 请求失败，请稍后重试或检查组织 AI 设置 / MINIMAX_API_KEY。';
        }

        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'MiniMax 无法生成有效修改草稿。';
    }
}
