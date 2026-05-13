<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\OrganizerSettings;

use HiEvents\Http\Request\Organizer\Settings\PartialUpdateOrganizerSettingsRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PartialUpdateOrganizerSettingsValidationTest extends TestCase
{
    private function validate(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $rules = PartialUpdateOrganizerSettingsRequest::rules();

        return Validator::make($payload, $rules);
    }

    public function test_accepts_default_wallet_passes_enabled_true(): void
    {
        $validator = $this->validate([
            'default_wallet_passes_enabled' => true,
        ]);

        $this->assertFalse($validator->errors()->has('default_wallet_passes_enabled'));
    }

    public function test_accepts_default_wallet_passes_enabled_false(): void
    {
        $validator = $this->validate([
            'default_wallet_passes_enabled' => false,
        ]);

        $this->assertFalse($validator->errors()->has('default_wallet_passes_enabled'));
    }

    public function test_rejects_non_boolean_default_wallet_passes_enabled(): void
    {
        $validator = $this->validate([
            'default_wallet_passes_enabled' => 'not-a-boolean',
        ]);

        $this->assertTrue($validator->errors()->has('default_wallet_passes_enabled'));
    }

    public function test_omitting_default_wallet_passes_enabled_is_allowed(): void
    {
        $validator = $this->validate([]);

        $this->assertFalse($validator->errors()->has('default_wallet_passes_enabled'));
    }
}
