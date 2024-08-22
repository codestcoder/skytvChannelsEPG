<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SkyEPGController extends Controller
{
    public function getSkyEPGData(Request $request)
    {
        $timerStart = microtime(true);
        $days = 2;
        $region = 1;

        $channelNameUri = "https://www.mediamole.co.uk/entertainment/broadcasting/information/sky-full-channels-list-epg-numbers-and-local-differences_441957.html";
        $channelNames = $this->getChannelNames($channelNameUri);

        $channelDetailsUri = "https://awk.epgsky.com/hawk/linear/services/4101/{$region}";
        $channelDetails = $this->getChannelDetails($channelDetailsUri, $channelNames);

        DB::table('sky_schedule')->truncate();

        $this->getEpgUris($channelDetails, $days);

        $timerEnd = microtime(true);
        $executionTime = round($timerEnd - $timerStart, 2);

        return response()->json([
            'message' => 'Data fetched, cleared previous data, and stored new data successfully',
            'execution_time' => "{$executionTime} seconds"
        ], 200);
    }

    private function getChannelNames($url)
    {
        $page = file_get_contents($url);
        $doc = new \DOMDocument();
        @$doc->loadHTML($page);
        $xpath = new \DOMXPath($doc);

        $channels = [];
        $strongTags = $xpath->query('//strong');
        foreach ($strongTags as $strongTag) {
            $text = $strongTag->nodeValue;
            if (strpos($text, ':') !== false && strpos($text, ' ') === false) {
                $parts = explode(':', $text);
                $channelNumber = trim($parts[0]);
                $channelName = trim(explode('(', $strongTag->nextSibling->nodeValue)[0]);
                $channels[$channelNumber] = [$channelName, "", ""];
            }
        }

        return $channels;
    }

    private function getChannelDetails($url, $channelNames)
    {
        $response = Http::get($url);
        if ($response->failed()) {
            return [];
        }

        $skyChannelDetails = $response->json();
        if (!isset($skyChannelDetails['services'])) {
            return [];
        }

        foreach ($skyChannelDetails['services'] as $service) {
            $sid = $service['sid'];
            $channelNumber = $service['c'];
            $channelTitle = $service['t'];
            if (isset($channelNames[$channelNumber])) {
                $channelNames[$channelNumber][1] = $channelTitle;
                $channelNames[$channelNumber][2] = $sid;
            }
        }

        return $channelNames;
    }

    private function getListings($url)
    {
        return Http::get($url)->json();
    }

    private function programs($daysListings, $channelDetails)
    {
        $dateFetched = Carbon::today()->format('Y-m-d');

        foreach ($daysListings['schedule'] as $schedule) {
            $channelId = $schedule['sid'];
            foreach ($schedule['events'] as $program) {
                $scheduleDate = Carbon::createFromTimestamp($program['st'])->format('Y-m-d');
                $startTime = Carbon::createFromTimestamp($program['st'])->format('H:i');
                $endTime = Carbon::createFromTimestamp($program['st'] + $program['d'])->format('H:i');
                $title = $program['t'];
                $desc = $program['sy'] ?? "";

                foreach ($channelDetails as $channelNumber => $details) {
                    if ($details[2] === $channelId) {
                        DB::table('sky_schedule')->insert([
                            'date_fetched' => $dateFetched,
                            'ch_no' => $channelNumber,
                            'ch_name' => $details[0],
                            'network_name' => $details[1],
                            'sid' => $channelId,
                            'date' => $scheduleDate,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'prog_title' => $title,
                            'prog_desc' => $desc
                        ]);
                    }
                }
            }
        }
    }

    private function getEpgUris($channelDetails, $days)
    {
        $chunks = array_chunk(array_keys($channelDetails), 10);

        foreach ($chunks as $chunk) {
            $listOfSids = array_filter(array_map(fn($key) => $channelDetails[$key][2] ?? null, $chunk));
            $stringOfSids = implode(',', $listOfSids);

            for ($x = 0; $x < $days; $x++) {
                $listingDay = Carbon::now()->addDays($x)->format('Ymd');
                $epgUri = "https://awk.epgsky.com/hawk/linear/schedule/{$listingDay}/{$stringOfSids}";
                $dayListings = $this->getListings($epgUri);
                $this->programs($dayListings, $channelDetails);
            }
        }
    }
}
