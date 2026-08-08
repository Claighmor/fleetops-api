<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'document';

    /**
     * Documents that are expired or approaching expiry, most urgent first.
     *
     * Backs the fleet-wide compliance view: "what is out of date, and what is
     * about to be". Reads the (company_uuid, end_date) index.
     *
     * @return \Illuminate\Http\Response
     */
    public function expiring(Request $request)
    {
        $within  = (int) $request->input('within', 30);
        $type    = $request->input('type');
        $include = $request->boolean('include_expired', true);

        $query = Document::where('company_uuid', session('company'))
            ->whereNotNull('end_date')
            ->with(['subject', 'file']);

        if ($type) {
            $query->ofType($type);
        }

        if ($include) {
            $query->where('end_date', '<=', now()->addDays($within));
        } else {
            $query->expiringSoon($within);
        }

        $documents = $query->orderBy('end_date', 'asc')->get();

        return \Fleetbase\FleetOps\Http\Resources\v1\Document::collection($documents);
    }
}
