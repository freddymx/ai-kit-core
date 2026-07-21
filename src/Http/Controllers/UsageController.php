<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\AiKit\Support\UsageStats;

final class UsageController
{
    public function __construct(private readonly UsageStats $usage)
    {
    }

    /** GET /usage?days=30 */
    public function index(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);

        return response()->json([
            'totals' => $this->usage->totals($days),
            'daily' => $this->usage->daily(min($days, 90)),
        ]);
    }
}
