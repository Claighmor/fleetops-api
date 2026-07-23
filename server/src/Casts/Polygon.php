<?php

namespace Fleetbase\FleetOps\Casts;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\GeometryInterface;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon as SpatialPolygon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class Polygon implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     */
    public function get($model, $key, $value, $attributes)
    {
        // PostGIS returns geometry as hex EWKB; convert to a Geometry object.
        if (is_string($value) && $value !== '' && ctype_xdigit($value) && strlen($value) >= 18) {
            $geometry = Utils::pgHexToGeometry($value);
            if ($geometry !== null) {
                return $geometry;
            }
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     */
    public function set($model, $key, $value, $attributes)
    {
        if ($value instanceof GeometryInterface) {
            $model->geometries[$key] = $value;

            return $value;
        }

        if ($value instanceof SpatialPolygon) {
            $model->geometries[$key] = $value;

            return $value;
        }

        if (Utils::isGeoJson($value)) {
            $value                   = Utils::createGeometryObjectFromGeoJson($value);
            $model->geometries[$key] = $value;

            return $value;
        }

        if ($value instanceof SpatialExpression) {
            $model->geometries[$key] = $value;

            return $value;
        }

        throw new \Exception('Invalid Polygon provided for ' . $key);
    }
}
