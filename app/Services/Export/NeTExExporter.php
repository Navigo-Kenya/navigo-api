<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Structural NeTEx XML stub, covers agencies (operators), stops (quays), and routes (lines).
 * Full NeTEx (timetables, vehicle journeys, etc.) is out of scope for this implementation.
 */
class NeTExExporter implements ExporterContract
{
    public function export(): string
    {
        $storageDir = storage_path('app/gtfs');
        File::ensureDirectoryExists($storageDir);
        $path = $storageDir . DIRECTORY_SEPARATOR . 'hopln_netex_' . now()->format('Ymd_His') . '.xml';

        $agencies = DB::table('agencies')->get();
        $stops    = DB::table('stops')
            ->selectRaw("id, name, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng")
            ->get();
        $routes   = DB::table('routes')
            ->select('route_id', 'route_short_name', 'route_long_name', 'route_type')
            ->get();

        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<PublicationDelivery xmlns="http://www.netex.org.uk/netex" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0"></PublicationDelivery>'
        );

        $dataObjects = $xml->addChild('dataObjects');
        $composite   = $dataObjects->addChild('CompositeFrame');
        $composite->addAttribute('id', 'hopln:CompositeFrame:1');
        $composite->addAttribute('version', '1');
        $frames = $composite->addChild('frames');

        // ── Resource Frame (Operators) ────────────────────────────────────────
        $resourceFrame = $frames->addChild('ResourceFrame');
        $resourceFrame->addAttribute('id', 'hopln:ResourceFrame:1');
        $resourceFrame->addAttribute('version', '1');
        $organisations = $resourceFrame->addChild('organisations');

        foreach ($agencies as $agency) {
            $op = $organisations->addChild('Operator');
            $op->addAttribute('id', 'hopln:Operator:' . $agency->agency_id);
            $op->addAttribute('version', '1');
            $op->addChild('Name', htmlspecialchars($agency->agency_name));
            if (!empty($agency->agency_url)) {
                $op->addChild('ContactDetails')->addChild('Url', htmlspecialchars($agency->agency_url));
            }
        }

        // ── Site Frame (Stops / Quays) ────────────────────────────────────────
        $siteFrame = $frames->addChild('SiteFrame');
        $siteFrame->addAttribute('id', 'hopln:SiteFrame:1');
        $siteFrame->addAttribute('version', '1');
        $stopPlaces = $siteFrame->addChild('stopPlaces');

        foreach ($stops as $stop) {
            $sp = $stopPlaces->addChild('StopPlace');
            $sp->addAttribute('id', 'hopln:StopPlace:' . $stop->id);
            $sp->addAttribute('version', '1');
            $sp->addChild('Name', htmlspecialchars($stop->name));
            $centroid = $sp->addChild('Centroid');
            $location = $centroid->addChild('Location');
            $location->addChild('Longitude', (string) round((float) $stop->lng, 6));
            $location->addChild('Latitude',  (string) round((float) $stop->lat, 6));
        }

        // ── Service Frame (Lines / Routes) ────────────────────────────────────
        $serviceFrame = $frames->addChild('ServiceFrame');
        $serviceFrame->addAttribute('id', 'hopln:ServiceFrame:1');
        $serviceFrame->addAttribute('version', '1');
        $lines = $serviceFrame->addChild('lines');

        foreach ($routes as $route) {
            $line = $lines->addChild('Line');
            $line->addAttribute('id', 'hopln:Line:' . $route->route_id);
            $line->addAttribute('version', '1');
            $line->addChild('Name',      htmlspecialchars($route->route_long_name));
            $line->addChild('ShortName', htmlspecialchars($route->route_short_name));
            $line->addChild('TransportMode', $this->gtfsTypeToNeTEx((int) $route->route_type));
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;
        File::put($path, $dom->saveXML());

        return $path;
    }

    private function gtfsTypeToNeTEx(int $gtfsType): string
    {
        return match ($gtfsType) {
            0       => 'tram',
            1       => 'metro',
            2       => 'rail',
            3       => 'bus',
            4       => 'water',
            7       => 'funicular',
            default => 'bus',
        };
    }

    public function getMimeType(): string
    {
        return 'application/xml';
    }

    public function getFilename(): string
    {
        return 'hopln_netex.xml';
    }
}
