<?php

namespace Database\Factories;

use App\Models\CallActionItem;
use App\Models\Call;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallActionItemFactory extends Factory
{
    protected $model = CallActionItem::class;

    public function definition(): array
    {
        $call = Call::factory()->create();

        return [
            'call_action_item_id' => 'CAI_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'call_id' => $call->call_id,
            'description' => $this->faker->sentence(8),
            'assigned_to' => User::factory()->create()->user_id,
            'due_date' => $this->faker->optional()->date(),
            'status' => 'pending',
        ];
    }
}
