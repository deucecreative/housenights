<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

class RequestAffiliateMagicLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
