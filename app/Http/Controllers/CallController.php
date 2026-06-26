<?php

namespace App\Http\Controllers;

use App\Enums\CallActionItemStatus;
use App\Models\Call;
use App\Models\CallActionItem;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class CallController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Call::with(['trip', 'organizedBy'])->orderBy('started_at', 'desc')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'organized_by' => 'nullable|string|exists:users,user_id',
            'title' => 'nullable|string|max:200',
            'started_at' => 'nullable|date',
            'meeting_link' => 'nullable|string|max:500',
        ]);

        $validated['call_id'] = IdGeneratorService::generateId('CAL');
        $call = Call::create($validated);

        return response()->json($call, 201);
    }

    public function show(Call $call): JsonResponse
    {
        return response()->json($call->load(['trip', 'organizedBy', 'actionItems']));
    }

    public function update(Request $request, Call $call): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
            'meeting_link' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            'transcript' => 'nullable|string',
        ]);

        $call->update($validated);

        return response()->json($call);
    }

    public function destroy(Call $call): JsonResponse
    {
        $call->delete();
        return response()->json(null, 204);
    }

    public function endCall(Call $call): JsonResponse
    {
        $call->update(['ended_at' => now()]);

        return response()->json($call);
    }

    // Action Items
    public function addActionItem(Request $request, Call $call): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string',
        ]);

        $validated['action_item_id'] = IdGeneratorService::generateId('ACI');
        $validated['call_id'] = $call->call_id;
        $item = CallActionItem::create($validated);

        return response()->json($item, 201);
    }

    public function updateActionItem(Request $request, CallActionItem $callActionItem): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'sometimes|required|string',
            'status' => ['nullable', new Enum(CallActionItemStatus::class)],
        ]);

        $callActionItem->update($validated);

        return response()->json($callActionItem);
    }

    public function removeActionItem(CallActionItem $callActionItem): JsonResponse
    {
        $callActionItem->delete();
        return response()->json(null, 204);
    }
}
