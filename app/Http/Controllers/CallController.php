<?php

namespace App\Http\Controllers;

use App\Enums\CallActionItemStatus;
use App\Helpers\CallHelper;
use App\Helpers\UserHelper;
use App\Models\Call;
use App\Models\CallActionItem;
use App\Models\Trip;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Manages trip calls and their associated action items.
 *
 * Routes: /api/calls, /api/calls/{call}/action-items, /api/calls/action-items/{actionItem}
 */
class CallController extends Controller
{
    // GET /api/calls — Returns paginated list of calls scoped to the user's active company,
    // ordered by started_at desc. Accepts an optional ?trip_id= filter (used by the frontend's
    // TripDetail "Calls" tab to only show calls for the trip currently being viewed).
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $query = Call::with(['trip', 'organizedBy', 'actionItems'])
            ->whereIn('trip_id', function ($q) use ($company) {
                $q->select('trip_id')->from('trips')->where('company_id', $company->company_id);
            })
            ->orderBy('started_at', 'desc');

        if ($request->filled('trip_id')) {
            $query->where('trip_id', $request->input('trip_id'));
        }

        return response()->json($query->paginate(15));
    }

    // POST /api/calls — Creates a new call record for one of the user's own trips.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'organized_by' => 'nullable|string|exists:users,user_id',
            'title' => 'nullable|string|max:200',
            'started_at' => 'nullable|date',
            'meeting_link' => 'nullable|string|max:500',
        ]);

        $company = UserHelper::user_company($request);
        $trip = Trip::where('company_id', $company->company_id)
            ->where('trip_id', $validated['trip_id'])
            ->first();

        if (!$trip) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated['call_id'] = IdGeneratorService::generateId('CAL');
        $call = Call::create($validated);

        return response()->json($call, 201);
    }

    // GET /api/calls/{call} — Returns a single call with its trip, organizer, and action items
    // (company-scoped).
    public function show(Request $request, Call $call): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($call->load(['trip', 'organizedBy', 'actionItems']));
    }

    // PUT/PATCH /api/calls/{call} — Updates call details (title, times, notes, transcript)
    // (company-scoped).
    public function update(Request $request, Call $call): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

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

    // DELETE /api/calls/{call} — Deletes a call record (company-scoped).
    public function destroy(Request $request, Call $call): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $call->delete();
        return response()->json(null, 204);
    }

    // POST /api/calls/{call}/end — Marks a call as ended by setting ended_at to now
    // (company-scoped).
    public function endCall(Request $request, Call $call): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $call->update(['ended_at' => now()]);

        return response()->json($call);
    }

    // POST /api/calls/{call}/action-items — Adds an action item to a call (company-scoped).
    public function addActionItem(Request $request, Call $call): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'description' => 'required|string',
        ]);

        $validated['action_item_id'] = IdGeneratorService::generateId('ACI');
        $validated['call_id'] = $call->call_id;
        $item = CallActionItem::create($validated);

        return response()->json($item, 201);
    }

    // PUT/PATCH /api/calls/action-items/{callActionItem} — Updates an action item's
    // description or status (company-scoped via its parent call's trip).
    public function updateActionItem(Request $request, CallActionItem $callActionItem): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $callActionItem->call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'description' => 'sometimes|required|string',
            'status' => ['nullable', new Enum(CallActionItemStatus::class)],
        ]);

        $callActionItem->update($validated);

        return response()->json($callActionItem);
    }

    // DELETE /api/calls/action-items/{callActionItem} — Removes an action item (company-scoped).
    public function removeActionItem(Request $request, CallActionItem $callActionItem): JsonResponse
    {
        if (!CallHelper::is_user_company_call($request, $callActionItem->call)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $callActionItem->delete();
        return response()->json(null, 204);
    }
}
