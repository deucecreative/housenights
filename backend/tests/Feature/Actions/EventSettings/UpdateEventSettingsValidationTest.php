<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\EventSettings;

use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateEventSettingsValidationTest extends TestCase
{
    private function validate(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $rules = (new UpdateEventSettingsRequest())->rules();

        return Validator::make($payload, $rules);
    }

    public function test_accepts_valid_pre_event_reminder_fields(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_enabled' => false,
            'pre_event_reminder_hours' => 48,
        ]);

        $this->assertFalse($validator->errors()->has('pre_event_reminder_enabled'));
        $this->assertFalse($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_accepts_pre_event_reminder_enabled_true(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_enabled' => true,
            'pre_event_reminder_hours' => 24,
        ]);

        $this->assertFalse($validator->errors()->has('pre_event_reminder_enabled'));
        $this->assertFalse($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_rejects_hours_below_min(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_hours' => 0,
        ]);

        $this->assertTrue($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_rejects_hours_above_max(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_hours' => 200,
        ]);

        $this->assertTrue($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_rejects_non_integer_hours(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_hours' => 'not-a-number',
        ]);

        $this->assertTrue($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_omitting_pre_event_reminder_fields_is_allowed(): void
    {
        $validator = $this->validate([]);

        $this->assertFalse($validator->errors()->has('pre_event_reminder_enabled'));
        $this->assertFalse($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_accepts_hours_at_min_boundary(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_hours' => 1,
        ]);

        $this->assertFalse($validator->errors()->has('pre_event_reminder_hours'));
    }

    public function test_accepts_hours_at_max_boundary(): void
    {
        $validator = $this->validate([
            'pre_event_reminder_hours' => 168,
        ]);

        $this->assertFalse($validator->errors()->has('pre_event_reminder_hours'));
    }
}
