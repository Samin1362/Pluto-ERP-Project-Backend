<?php

namespace App\Http\Requests\Me;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** PUT /me/preferences (UserPreferences; K13). */
class PreferencesRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['theme', 'density', 'text_size', 'reduce_motion', 'sidebar_collapsed'];
    }

    public function rules(): array
    {
        return ['theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])], 'density' => ['sometimes', Rule::in(['default', 'compact'])],
            'text_size' => ['sometimes', Rule::in(['s', 'm', 'l'])], 'reduce_motion' => ['sometimes', 'boolean'], 'sidebar_collapsed' => ['sometimes', 'boolean']];
    }
}
