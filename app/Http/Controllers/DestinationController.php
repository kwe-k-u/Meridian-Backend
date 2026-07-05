<?php

namespace App\Http\Controllers;

use App\Helpers\DestinationHelper;
use App\Models\Destination;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles CRUD operations for travel destinations.
 *
 * Routes: /api/destinations (resourceful)
 */
class DestinationController extends Controller
{
    // GET /api/destinations — Returns paginated list of destinations ordered by name.
    public function index(): JsonResponse
    {
        return response()->json(Destination::orderBy('name')->paginate(15));
    }

    // POST /api/destinations — Creates a new destination.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'url' => 'nullable|string|max:500',
        ]);

        $validated['destination_id'] = IdGeneratorService::generateId('DST');
        $destination = Destination::create($validated);

        return response()->json($destination, 201);
    }

    // GET /api/destinations/{destination} — Returns a single destination.
    public function show(Request $request, Destination $destination): JsonResponse
    {
        if (!DestinationHelper::user_company_dest($request, $destination)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($destination);
    }

    // PUT/PATCH /api/destinations/{destination} — Updates a destination's name, country, or URL.
    public function update(Request $request, Destination $destination): JsonResponse
    {
        if (!DestinationHelper::user_company_dest($request, $destination)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'country' => 'nullable|string|max:100',
            'url' => 'nullable|string|max:500',
        ]);

        $destination->update($validated);

        return response()->json($destination);
    }

    // DELETE /api/destinations/{destination} — Deletes a destination.
    public function destroy(Request $request, Destination $destination): JsonResponse
    {
        if (!DestinationHelper::user_company_dest($request, $destination)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $destination->delete();
        return response()->json(null, 204);
    }
}
