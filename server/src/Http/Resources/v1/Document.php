<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Document extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'            => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'       => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'    => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'type'            => $this->type,
            'document_number' => $this->document_number,
            'provider'        => $this->provider,
            'jurisdiction'    => $this->jurisdiction,
            'lienholder'      => $this->lienholder,
            'issued_at'       => $this->issued_at,
            'start_date'      => $this->start_date,
            'end_date'        => $this->end_date,
            'status'          => $this->status,
            'is_expired'      => $this->is_expired,
            'days_remaining'  => $this->days_remaining,
            'subject_uuid'    => $this->when(Http::isInternalRequest(), $this->subject_uuid),
            'subject_type'    => $this->when(Http::isInternalRequest(), $this->subject_type ? Utils::toEmberResourceType($this->subject_type) : null),
            'subject_name'    => $this->subject_name,
            'subject'         => $this->whenLoaded('subject', fn () => $this->resolveLoadedRelation($this->subject)),
            'file_uuid'       => $this->when(Http::isInternalRequest(), $this->file_uuid),
            'file'            => $this->whenLoaded('file', fn () => $this->resolveLoadedRelation($this->file)),
            'vendor_uuid'     => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'notes'           => $this->notes,
            'meta'            => $this->meta,
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
