<?php

namespace App\Http\Controllers;

use App\Enums\CallActionItemStatus;
use App\Enums\TransactionStatus;
use App\Models\CallActionItem;
use App\Models\Transaction;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Aggregates dashboard metrics (revenue, outstanding, refunds, tasks) for the authenticated user's companies.
 *
 * Routes: GET /api/dashboard
 */
class DashboardController extends Controller
{
    // GET /api/dashboard — Returns aggregated revenue, outstanding, refund, trip, and task metrics for the user's companies.
    public function __invoke(Request $request)
    {
        $user = $request->user();
        // Note: this uses ALL companies the user is a member of (not just the active one),
        // unlike most other controllers which scope to UserHelper::user_company()'s single
        // active company. So dashboard totals can include more than one company's data.
        $companyIds = $user->companies->pluck('company_id');

        // start_date/end_date are cast to Carbon dates (see Trip::$casts) so left as-is they'd
        // serialize with a full ISO datetime + timezone (e.g. "2026-09-10T00:00:00.000000Z").
        // The frontend's fmtDate() expects a plain "YYYY-MM-DD" (it appends its own "T00:00:00"
        // before parsing — see Dashboard.tsx), so map to toDateString() the same way
        // ConversationController/TripController already do for trip dates.
        $latestTrips = Trip::whereIn('company_id', $companyIds)
            ->orderBy('created_at', 'desc')
            ->take(4)
            ->get(['trip_id', 'trip_name', 'status', 'start_date', 'end_date'])
            ->map(fn (Trip $trip) => [
                'trip_id' => $trip->trip_id,
                'trip_name' => $trip->trip_name,
                'status' => $trip->status->value,
                'start_date' => $trip->start_date?->toDateString(),
                'end_date' => $trip->end_date?->toDateString(),
            ]);

        // Each of the four metrics below re-runs the same "find transactions whose
        // trip_payment.trip belongs to one of my companies" subquery, then filters by
        // transaction status. completed = revenue, pending = outstanding/unpaid,
        // refunded = refunds, completed+pending = paid_out ("money that has moved or will").
        $totalRevenue = Transaction::whereIn('transaction_id', function ($q) use ($companyIds) {
            $q->select('trip_payments.transaction_id')
              ->from('trip_payments')
              ->join('trips', 'trips.trip_id', '=', 'trip_payments.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->where('status', TransactionStatus::COMPLETED)->sum('amount');

        $outstanding = Transaction::whereIn('transaction_id', function ($q) use ($companyIds) {
            $q->select('trip_payments.transaction_id')
              ->from('trip_payments')
              ->join('trips', 'trips.trip_id', '=', 'trip_payments.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->where('status', TransactionStatus::PENDING);

        $refunds = Transaction::whereIn('transaction_id', function ($q) use ($companyIds) {
            $q->select('trip_payments.transaction_id')
              ->from('trip_payments')
              ->join('trips', 'trips.trip_id', '=', 'trip_payments.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->where('status', TransactionStatus::REFUNDED);

        $paidOut = Transaction::whereIn('transaction_id', function ($q) use ($companyIds) {
            $q->select('trip_payments.transaction_id')
              ->from('trip_payments')
              ->join('trips', 'trips.trip_id', '=', 'trip_payments.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->whereIn('status', [TransactionStatus::COMPLETED, TransactionStatus::PENDING]);

        // "AI handled" vs "pending review" tasks come from call action items across all of
        // this user's companies' calls — CHECKED means an agent already reviewed/actioned it.
        $aiHandled = CallActionItem::whereIn('call_id', function ($q) use ($companyIds) {
            $q->select('calls.call_id')
              ->from('calls')
              ->join('trips', 'trips.trip_id', '=', 'calls.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->where('status', CallActionItemStatus::CHECKED)->count();

        $pendingReview = CallActionItem::whereIn('call_id', function ($q) use ($companyIds) {
            $q->select('calls.call_id')
              ->from('calls')
              ->join('trips', 'trips.trip_id', '=', 'calls.trip_id')
              ->whereIn('trips.company_id', $companyIds);
        })->where('status', CallActionItemStatus::PENDING)->count();

        return response()->json([
            'user' => $user,
            'revenue' => [
                'amount' => (float) $totalRevenue,
                'previous_cmp' => 0, // Period-over-period comparison isn't implemented yet — always 0 for now.
            ],
            'outstanding' => [
                'amount' => (float) $outstanding->sum('amount'),
                'count' => $outstanding->count(),
            ],
            'paid_out' => [
                'amount' => (float) $paidOut->sum('amount'),
                'next_payout' => Carbon::now()->endOfMonth()->toDateString(),
            ],
            'refunds' => [
                'amount' => (float) $refunds->sum('amount'),
                'count' => $refunds->count(),
            ],
            'latest_trips' => $latestTrips,
            'ai_handled_tasks' => $aiHandled,
            'pending_review_tasks' => $pendingReview,
        ]);
    }
}
