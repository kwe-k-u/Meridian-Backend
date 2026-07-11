<?php

namespace Database\Seeders;

use App\Enums\AccommodationStatus;
use App\Enums\CallActionItemStatus;
use App\Enums\CompanyRole;
use App\Enums\CustomerStatus;
use App\Enums\FlightStatus;
use App\Enums\ItineraryStatus;
use App\Enums\TransactionStatus;
use App\Enums\TripCustomerRole;
use App\Enums\TripStatus;
use App\Models\Call;
use App\Models\CallActionItem;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryAccommodation;
use App\Models\ItineraryDay;
use App\Models\ItineraryDayDestination;
use App\Models\ItineraryFlight;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\TripPayment;
use App\Models\User;
use App\Services\IdGeneratorService;
use App\Enums\UserStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a complete demo environment for the Meridian Travels application.
 *
 * Creates: a company (Meridian Travels), a demo user, 8 destinations
 * (Accra, Cape Coast, Kumasi, London, New York, Dubai, Lagos, Nairobi),
 * 4 customers, 3 trips (Ghana Business Summit, London Fashion Week,
 * Dubai Executive Retreat) with an itinerary, flight, accommodation,
 * call records with action items, and sample transactions.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'demo@meridian.com';
        $password = 'demoPassword4Meridian$';

        try {
            DB::beginTransaction();

            $company = Company::create([
                'company_id' => IdGeneratorService::generateId('CMP'),
                'company_name' => 'Meridian Travels',
                'country' => 'Ghana',
                'city_of_operation' => 'Accra',
                'status' => true,
            ]);

            $user = User::create([
                'user_id' => IdGeneratorService::generateId('USR'),
                'email' => $email,
                'display_name' => 'Meridian Demo',
                'password' => Hash::make($password),
                'status' => UserStatus::ACTIVE,
                'last_login' => now(),
            ]);

            $company->users()->attach($user->user_id, [
                'role' => CompanyRole::OWNER->value,
                'is_default' => true,
                'is_enabled' => true,
                'joined_at' => now(),
            ]);

            $destinations = $this->seedDestinations();
            $customers = $this->seedCustomers($company->company_id);
            $trips = $this->seedTrips($company->company_id, $user->user_id, $customers);
            $this->seedItineraries($trips[0], $user->user_id, $destinations);
            $this->seedCalls($trips[0], $user->user_id);
            $this->seedTransactions($trips[0], $company->company_id);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function seedDestinations(): array
    {
        $data = [
            ['name' => 'Safari Valley', 'country' => 'Ghana'],
            ['name' => 'Cape Coast Castle', 'country' => 'Ghana'],
            ['name' => 'Kwame Nkrumah Museum', 'country' => 'Ghana'],
            ['name' => 'London', 'country' => 'United Kingdom'],
            ['name' => 'New York', 'country' => 'USA'],
            ['name' => 'Dubai', 'country' => 'UAE'],
            ['name' => 'Lagos', 'country' => 'Nigeria'],
            ['name' => 'Nairobi', 'country' => 'Kenya'],
        ];

        $destinations = [];
        foreach ($data as $item) {
            $destinations[] = Destination::create([
                'destination_id' => IdGeneratorService::generateId('DST'),
                'name' => $item['name'],
                'country' => $item['country'],
                'created_at' => now(),
            ]);
        }

        return $destinations;
    }

    private function seedCustomers(string $companyId): array
    {
        $data = [
            ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'phone' => '+233501234567', 'nationality' => 'Ghanaian'],
            ['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com', 'phone' => '+233501234568', 'nationality' => 'Ghanaian'],
            ['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.com', 'phone' => '+233501234569', 'nationality' => 'Nigerian'],
            ['first_name' => 'Bob', 'last_name' => 'Williams', 'email' => 'bob@example.com', 'phone' => '+233501234570', 'nationality' => 'British'],
        ];

        $customers = [];
        foreach ($data as $item) {
            $customers[] = Customer::create([
                'customer_id' => IdGeneratorService::generateId('CUS'),
                'company_id' => $companyId,
                'first_name' => $item['first_name'],
                'last_name' => $item['last_name'],
                'email' => $item['email'],
                'phone' => $item['phone'],
                'nationality' => $item['nationality'],
                'status' => CustomerStatus::ACTIVE,
            ]);
        }

        return $customers;
    }

    private function seedTrips(string $companyId, string $userId, array $customers): array
    {
        $tripsData = [
            [
                'trip_name' => 'Ghana Business Summit 2026',
                'description' => 'Annual business summit in Accra with site visits to Cape Coast and Kumasi.',
                'start_date' => '2026-08-15',
                'end_date' => '2026-08-22',
                'budget' => '25000',
                'status' => TripStatus::PLANNING,
            ],
            [
                'trip_name' => 'London Fashion Week',
                'description' => 'Attend London Fashion Week with client meetings.',
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-18',
                'budget' => '35000',
                'status' => TripStatus::INQUIRY,
            ],
            [
                'trip_name' => 'Dubai Executive Retreat',
                'description' => 'Executive team building and strategy retreat in Dubai.',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-10',
                'budget' => '45000',
                'status' => TripStatus::BOOKED,
            ],
        ];

        $trips = [];
        foreach ($tripsData as $i => $data) {
            $trip = Trip::create([
                'trip_id' => IdGeneratorService::generateId('TRP'),
                'company_id' => $companyId,
                'created_by' => $userId,
                'trip_name' => $data['trip_name'],
                'description' => $data['description'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'budget' => $data['budget'],
                'status' => $data['status'],
            ]);

            $trip->customers()->attach($customers[$i % count($customers)]->customer_id, [
                'role' => TripCustomerRole::PRIMARY->value,
                'added_at' => now(),
            ]);

            $trips[] = $trip;
        }

        return $trips;
    }

    private function seedItineraries(Trip $trip, string $userId, array $destinations): void
    {
        $itinerary = Itinerary::create([
            'itinerary_id' => IdGeneratorService::generateId('ITN'),
            'trip_id' => $trip->trip_id,
            'created_by' => $userId,
            'itinerary_name' => $trip->trip_name . ' - Itinerary',
            'description' => 'Detailed itinerary for ' . $trip->trip_name,
            'start_date' => $trip->start_date,
            'end_date' => $trip->end_date,
            'status' => ItineraryStatus::DRAFT,
        ]);

        $day1 = ItineraryDay::create([
            'itinerary_day_id' => IdGeneratorService::generateId('ITD'),
            'itinerary_id' => $itinerary->itinerary_id,
            'day_number' => 1,
            'date' => $trip->start_date,
            'title' => 'Arrival and Welcome',
            'description' => 'Arrival at airport, transfer to hotel, welcome dinner.',
            'location' => 'Accra',
        ]);

        ItineraryDay::create([
            'itinerary_day_id' => IdGeneratorService::generateId('ITD'),
            'itinerary_id' => $itinerary->itinerary_id,
            'day_number' => 2,
            'date' => Carbon::parse($trip->start_date)->addDay(),
            'title' => 'Business Meetings',
            'description' => 'Full day of business meetings and presentations.',
            'location' => 'Accra',
        ]);

        ItineraryDayDestination::create([
            'itinerary_day_id' => $day1->itinerary_day_id,
            'destination_id' => $destinations[0]->destination_id,
            'cost' => '500',
            'currency' => 'GHS',
            'activities' => 'Airport pickup, hotel check-in, welcome dinner',
        ]);

        ItineraryFlight::create([
            'flight_id' => IdGeneratorService::generateId('FLT'),
            'itinerary_id' => $itinerary->itinerary_id,
            'airline' => 'Delta Airlines',
            'flight_number' => 'DL1234',
            'departure_airport' => 'JFK',
            'arrival_airport' => 'ACC',
            'departure_datetime' => Carbon::parse($trip->start_date)->subDay()->setHour(22)->setMinute(30),
            'arrival_datetime' => Carbon::parse($trip->start_date)->setHour(14)->setMinute(00),
            'cost' => 1200,
            'currency' => 'USD',
            'booking_reference' => 'DLBKG001',
            'status' => FlightStatus::BOOKED,
        ]);

        ItineraryAccommodation::create([
            'accommodation_id' => IdGeneratorService::generateId('ACC'),
            'itinerary_id' => $itinerary->itinerary_id,
            'accommodation_name' => 'Kempinski Hotel Gold Coast City',
            'address' => 'Gamel Abdul Nasser Ave, Accra, Ghana',
            'check_in_date' => $trip->start_date,
            'check_out_date' => Carbon::parse($trip->end_date),
            'room_type' => 'Deluxe Suite',
            'cost' => 3000,
            'currency' => 'GHS',
            'booking_reference' => 'KMP001',
            'status' => AccommodationStatus::BOOKED,
        ]);
    }

    private function seedCalls(Trip $trip, string $userId): void
    {
        $call = Call::create([
            'call_id' => IdGeneratorService::generateId('CAL'),
            'trip_id' => $trip->trip_id,
            'organized_by' => $userId,
            'title' => 'Trip Planning Kickoff',
            'started_at' => Carbon::now()->subDays(5)->setHour(10)->setMinute(0),
            'ended_at' => Carbon::now()->subDays(5)->setHour(11)->setMinute(30),
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            'notes' => 'Discussed itinerary, budget, and client requirements.',
        ]);

        foreach ([
            ['description' => 'Send welcome package to clients', 'status' => CallActionItemStatus::CHECKED],
            ['description' => 'Confirm flight bookings', 'status' => CallActionItemStatus::CHECKED],
            ['description' => 'Prepare meeting agenda for Day 2', 'status' => CallActionItemStatus::PENDING],
            ['description' => 'Arrange airport transfers', 'status' => CallActionItemStatus::PENDING],
        ] as $item) {
            CallActionItem::create([
                'action_item_id' => IdGeneratorService::generateId('ACT'),
                'call_id' => $call->call_id,
                'description' => $item['description'],
                'status' => $item['status'],
            ]);
        }

        $call2 = Call::create([
            'call_id' => IdGeneratorService::generateId('CAL'),
            'trip_id' => $trip->trip_id,
            'organized_by' => $userId,
            'title' => 'Client Requirements Review',
            'started_at' => Carbon::now()->subDays(2)->setHour(14)->setMinute(0),
            'ended_at' => Carbon::now()->subDays(2)->setHour(15)->setMinute(15),
            'meeting_link' => 'https://zoom.us/j/1234567890',
            'notes' => 'Client confirmed all requirements. Special dietary needs noted.',
        ]);

        CallActionItem::create([
            'action_item_id' => IdGeneratorService::generateId('ACT'),
            'call_id' => $call2->call_id,
            'description' => 'Update meal preferences with hotels',
            'status' => CallActionItemStatus::PENDING,
        ]);
    }

    private function seedTransactions(Trip $trip, string $companyId): void
    {
        $transaction = Transaction::create([
            'transaction_id' => IdGeneratorService::generateId('TXN'),
            'amount' => 5000.00,
            'currency' => 'USD',
            'status' => TransactionStatus::COMPLETED,
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'TXN-REF-001',
            'paid_at' => Carbon::now()->subDays(3),
        ]);

        TripPayment::create([
            'transaction_id' => $transaction->transaction_id,
            'trip_id' => $trip->trip_id,
            'notes' => 'Initial deposit for trip booking.',
        ]);

        $transaction2 = Transaction::create([
            'transaction_id' => IdGeneratorService::generateId('TXN'),
            'amount' => 2500.00,
            'currency' => 'GHS',
            'status' => TransactionStatus::PENDING,
            'payment_method' => 'mobile_money',
            'transaction_reference' => 'TXN-REF-002',
            'paid_at' => null,
        ]);

        TripPayment::create([
            'transaction_id' => $transaction2->transaction_id,
            'trip_id' => $trip->trip_id,
            'notes' => 'Pending payment for accommodation.',
        ]);
    }
}
