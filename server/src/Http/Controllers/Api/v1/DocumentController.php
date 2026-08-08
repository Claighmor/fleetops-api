<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateDocumentRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Document as DocumentResource;
use Fleetbase\FleetOps\Models\Document;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreateDocumentRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        $input                    = $this->input($request);
        $input['company_uuid']    = session('company');
        $input['created_by_uuid'] = session('user');

        $document = Document::create($input)->load(['subject', 'file']);

        return new DocumentResource($document);
    }

    public function update(string $id, CreateDocumentRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        try {
            $document = $this->resolveModel(Document::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Document resource not found.'], 404);
        }

        $input                    = $this->input($request);
        $input['updated_by_uuid'] = session('user');

        $document->update($input);

        return new DocumentResource($document->refresh()->load(['subject', 'file']));
    }

    public function query(Request $request)
    {
        $this->rejectUuidIdentifiers($request);

        $results = Document::queryWithRequest($request, function (&$query) {
            $query->with(['subject', 'file']);
        });

        return DocumentResource::collection($results);
    }

    public function find(string $id)
    {
        try {
            $document = $this->resolveModel(Document::class, $id)->load(['subject', 'file']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Document resource not found.'], 404);
        }

        return new DocumentResource($document);
    }

    public function delete(string $id)
    {
        try {
            $document = $this->resolveModel(Document::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Document resource not found.'], 404);
        }

        $document->delete();

        return new DeletedResource($document);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'type',
            'document_number',
            'provider',
            'jurisdiction',
            'lienholder',
            'issued_at',
            'start_date',
            'end_date',
            'notes',
            'meta',
        ]);

        // The scan/PDF is referenced by its public id on the public API.
        $this->applyPublicIdRelation($input, 'file', 'file_uuid', File::class, $request);

        // Resolve the documented record from a type + public id pair, e.g.
        // { "subject_type": "vehicle", "subject_id": "vehicle_xxx" }.
        //
        // Normalize the bare form to the namespaced one first: `Utils::getMutationType`
        // maps a bare "driver" to Fleetbase\Models\Driver, which is not the FleetOps
        // model and is absent from the morph map, so it would resolve to nothing.
        $requestedType = $request->input('subject_type');
        if (is_string($requestedType) && !str_contains($requestedType, ':') && !str_contains($requestedType, '\\')) {
            $requestedType = 'fleet-ops:' . $requestedType;
        }

        [$subjectType, $subjectUuid] = $this->resolveMorph(
            $requestedType,
            $request->input('subject_id', $request->input('subject'))
        );

        if ($subjectType && $subjectUuid) {
            $input['subject_type'] = $subjectType;
            $input['subject_uuid'] = $subjectUuid;
        }

        return $input;
    }
}
