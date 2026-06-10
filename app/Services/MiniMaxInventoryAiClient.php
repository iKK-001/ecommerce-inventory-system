<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class MiniMaxInventoryAiClient
{
    private const TOOL_NAME = 'draft_inventory_operations';

    /**
     * @param  array<int, array<string, mixed>>  $skuContext
     * @return array{request: array<string, mixed>, response: array<string, mixed>, operations: array<int, array<string, mixed>>}
     */
    public function draftOperations(string $instruction, array $skuContext): array
    {
        $apiKey = (string) $this->setting('ai.minimax.api_key', config('services.minimax.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('MiniMax API key is not configured. Please set it in AI settings or MINIMAX_API_KEY.');
        }

        $baseUrl = rtrim((string) $this->setting(
            'ai.minimax.base_url',
            config('services.minimax.base_url', 'https://api.minimax.io/v1')
        ), '/');
        $payload = $this->payload($instruction, $skuContext);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(45)
            ->post($baseUrl.'/chat/completions', $payload)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new InvalidArgumentException('MiniMax returned an empty response.');
        }

        return [
            'request' => $payload,
            'response' => $response,
            'operations' => $this->operationsFromResponse($response),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $skuContext
     * @return array<string, mixed>
     */
    private function payload(string $instruction, array $skuContext): array
    {
        return [
            'model' => $this->setting('ai.minimax.model', config('services.minimax.model', 'MiniMax-M2.7')),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'You convert Chinese or English inventory instructions into structured operations.',
                        'Call the draft_inventory_operations tool exactly once.',
                        'Never invent SKUs. Use product_ref as the exact SKU or product name from the provided SKU_CONTEXT.',
                        'Do not execute changes. Do not include fields the user did not ask to change.',
                        'All currency fields are USD unit values. Stock quantities are absolute target quantities.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'instruction' => $instruction,
                        'supported_operations' => [
                            'cost_update',
                            'stock_set',
                            'in_transit_set',
                        ],
                        'supported_cost_fields' => [
                            'selling_price_usd',
                            'product_cost_usd',
                            'domestic_logistics_cost_usd',
                            'us_first_leg_cost_usd',
                            'us_last_mile_cost_usd',
                            'packing_cost_usd',
                        ],
                        'sku_context' => $skuContext,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            ],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => self::TOOL_NAME,
                        'description' => 'Create a draft list of inventory and cost operations from the user instruction.',
                        'parameters' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['operations'],
                            'properties' => [
                                'operations' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => true,
                                        'required' => ['type', 'product_ref'],
                                        'properties' => [
                                            'type' => [
                                                'type' => 'string',
                                                'enum' => ['cost_update', 'stock_set', 'in_transit_set'],
                                            ],
                                            'product_ref' => [
                                                'type' => 'string',
                                                'description' => 'Exact SKU or product name.',
                                            ],
                                            'fields' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'properties' => [
                                                    'selling_price_usd' => ['type' => 'number'],
                                                    'product_cost_usd' => ['type' => 'number'],
                                                    'domestic_logistics_cost_usd' => ['type' => 'number'],
                                                    'us_first_leg_cost_usd' => ['type' => 'number'],
                                                    'us_last_mile_cost_usd' => ['type' => 'number'],
                                                    'packing_cost_usd' => ['type' => 'number'],
                                                ],
                                            ],
                                            'actual_stock' => ['type' => 'integer', 'minimum' => 0],
                                            'in_transit_quantity' => ['type' => 'integer', 'minimum' => 0],
                                            'note' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => 2048,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function operationsFromResponse(array $response): array
    {
        $toolCalls = data_get($response, 'choices.0.message.tool_calls', []);
        if (! is_array($toolCalls)) {
            throw new InvalidArgumentException('MiniMax did not return a valid tool call.');
        }

        foreach ($toolCalls as $toolCall) {
            if (data_get($toolCall, 'function.name') !== self::TOOL_NAME) {
                continue;
            }

            $arguments = data_get($toolCall, 'function.arguments');
            if (is_array($arguments)) {
                $decoded = $arguments;
            } elseif (is_string($arguments) && $arguments !== '') {
                try {
                    $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new InvalidArgumentException('MiniMax returned invalid JSON arguments.');
                }
            } else {
                throw new InvalidArgumentException('MiniMax returned empty tool arguments.');
            }

            $operations = $decoded['operations'] ?? null;
            if (! is_array($operations)) {
                throw new InvalidArgumentException('MiniMax returned no operations.');
            }

            return array_values(array_filter($operations, 'is_array'));
        }

        throw new InvalidArgumentException('MiniMax did not call the expected inventory draft tool.');
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        try {
            $value = SettingsService::get($key, $default);
        } catch (RuntimeException) {
            return $default;
        }

        return filled($value) ? $value : $default;
    }
}
