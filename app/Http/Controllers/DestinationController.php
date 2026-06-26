<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Destination::orderBy('name')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'country' => 'nullable|string|max:100',
            'url' => 'nullable|string|max:500',
        ]);

        $validated['destination_id'] = IdGeneratorService::generateId('DST');
        $destination = Destination::create($validated);

        return response()->json($destination, 201);
    }

    public function show(Destination $destination): JsonResponse
    {
        return response()->json($destination);
    }

    public function update(Request $request, Destination $destination): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'country' => 'nullable|string|max:100',
            'url' => 'nullable|string|max:500',
        ]);

        $destination->update($validated);

        return response()->json($destination);
    }

    public function destroy(Destination $destination): JsonResponse
    {
        $destination->delete();
        return response()->json(null, 204);
    }
}
