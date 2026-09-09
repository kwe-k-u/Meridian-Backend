<?php

namespace App\Http\Controllers;

use App\Enums\CallActionItemStatus;
use App\Helpers\CallHelper;
use App\Helpers\UserHelper;
use App\Models\Call;
use App\Models\CallActionItem;
use App\Models\Customer;
use App\Models\GmailAccount;
use App\Models\Trip;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Google\GoogleCalendarService;
use App\Services\IdGeneratorService;
use DateTime;
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

    // POST /api/trips/{trip}/calls/schedule — Creates a Google Calendar event with an
    // auto-generated Meet link (via the company's connected Google account) plus a matching
    // Call row. Requires Calendar to be connected+enabled (see GmailController) — this doesn't
    // fall back to a plain manual call if it isn't.
    public function scheduleWithMeet(Request $request, Trip $trip, GmailOAuthService $oauth): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if ($trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $account = GmailAccount::where('company_id', $company->company_id)->first();
        if (!$account || !$account->calendar_enabled || $account->status !== 'active') {
            return response()->json(['message' => 'Connect Google Calendar in Settings first.'], 422);
        }
        if (!$account->meet_tracking_enabled) {
            return response()->json(['message' => 'Turn on Google Meet in Settings first.'], 422);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'started_at' => 'required|date',
            'ended_at' => 'required|date|after:started_at',
            'customer_id' => 'required|string|exists:customers,customer_id',
        ]);

        $customer = Customer::where('company_id', $company->company_id)
            ->where('customer_id', $validated['customer_id'])
            ->first();

        if (!$customer || !$customer->email) {
            return response()->json(['message' => 'That traveler needs an email address on file first.'], 422);
        }

        $calendar = new GoogleCalendarService($account, $oauth);
        $event = $calendar->createMeetEvent(
            $validated['title'],
            new DateTime($validated['started_at']),
            new DateTime($validated['ended_at']),
            [$customer->email],
        );

        $call = Call::create([
            'call_id' => IdGeneratorService::generateId('CAL'),
            'trip_id' => $trip->trip_id,
            'organized_by' => $request->user()->user_id,
            'title' => $validated['title'],
            'started_at' => $validated['started_at'],
            'meeting_link' => $event['hangout_link'],
            'google_event_id' => $event['event_id'],
        ]);

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
            // Only meaningful for calendar-originated calls (google_event_id set) — lets an
            // agent tell CalendarWatcherJob to leave a specific call alone, without disabling
            // Calendar tracking company-wide.
            'excluded' => 'nullable|boolean',
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
