<?php

namespace App\Http\Controllers;

use App\Enums\TripStatus;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Return the following:
     * - User infomation
     * - User company info
     * - Trips info
     * - Revenue info ( Total revenue, outstanding, paid out refunds)
     * - 
     * .
     */
    public function __invoke(Request $request)
    {
        $latest_trips = [
            [
                'trip_id' => 'TRP_PSFSKLSFJ2121',
                'trip_name' => 'Asante-Mensah Honeymoon',
                'status' => TripStatus::IN_PROGRESS->value,
                'start_date' => '2026-10-04',
                'end_date' => '2026-10-14',
            ],
            [
                'trip_id' => 'TRP_PSFSKLSFJ2122',
                'trip_name' => 'Adjei Family Dubai',
                'status' => TripStatus::PLANNING->value,
                'start_date' => '2026-07-12',
                'end_date' => '2026-07-19',
            ],
            [
                'trip_id' => 'TRP_PSFSKLSFJ2123',
                'trip_name' => 'Owusu Corporate Retreat',
                'status' => TripStatus::BOOKED->value,
                'start_date' => '2026-08-02',
                'end_date' => '2026-08-06',
            ],
            [
                'trip_id' => 'TRP_PSFSKLSFJ2124',
                'trip_name' => 'Boateng Anniversary',
                'status' => TripStatus::INQUIRY->value,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-15',
            ]
        ];

        return response()->json([
            'user' => $request->user(),
            'revenue' => [
                'amount' => 64300,
                'previous_cmp' => 1.8,
            ],
            'outstanding' => [
                'amount' => 64300,
                'count' => 3,
            ],
            'paid_out' => [
                'amount' => 64300,
                'next_payout' => '2026-06-24',
            ],
            'refunds' => [
                'amount' => 64300,
                'count' => 1
            ],
            'latest_trips' => $latest_trips,
            'ai_handled_tasks' => 14,
            'pending_review_tasks' => 3,
        ]);
    }
}
