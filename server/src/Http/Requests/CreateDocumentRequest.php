<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Models\Document;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateDocumentRequest extends FleetbaseRequest
{
    /**
     * Records a document may be attached to. Both the bare and namespaced forms
     * are accepted; the controller normalizes to the namespaced one.
     *
     * @var array<string>
     */
    public const SUBJECT_TYPES = [
        'vehicle',
        'driver',
        'equipment',
        'fleet-ops:vehicle',
        'fleet-ops:driver',
        'fleet-ops:equipment',
    ];

    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'type'            => [Rule::requiredIf($this->isMethod('POST')), Rule::in(Document::TYPES)],
            'document_number' => ['nullable', 'string', 'max:191'],
            'provider'        => ['nullable', 'string', 'max:191'],
            'jurisdiction'    => ['nullable', 'string', 'max:191'],
            'lienholder'      => ['nullable', 'string', 'max:191'],
            'issued_at'       => ['nullable', 'date'],
            'start_date'      => ['nullable', 'date'],
            // A document cannot expire before it starts.
            'end_date'        => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'           => ['nullable', 'string'],
            'meta'            => ['nullable', 'array'],
            'file'            => ['nullable', 'string'],
            'subject_type'    => ['nullable', 'required_with:subject_id', Rule::in(self::SUBJECT_TYPES)],
            'subject_id'      => ['nullable', 'string', 'required_with:subject_type'],
        ];
    }

    public function messages()
    {
        return [
            'type.in'         => 'The document type must be one of: ' . implode(', ', Document::TYPES) . '.',
            'subject_type.in' => 'The subject type must be one of: ' . implode(', ', self::SUBJECT_TYPES) . '.',
        ];
    }
}
