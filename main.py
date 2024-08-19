#!/usr/bin/python3

import sys
import requests
import json
import time
import datetime
import csv
from itertools import islice
from bs4 import BeautifulSoup

# parse through an external website and extract channel names and numbers
def get_channel_names(channel_name_uri):
    page = requests.get(channel_name_uri)
    soup = BeautifulSoup(page.content, "html.parser")
    channels = {}
    results = soup.find(id="article_body").find_all("p")

    for strong_tag in soup.find_all('strong'):
        if ":" in strong_tag.text:
            if " " not in strong_tag.text:
                channel_number = strong_tag.text.split(":")[0].strip()
                channel_name = strong_tag.next_sibling.split("(")[0].strip()
                channel_details = [channel_name, "", ""]
                channels[channel_number] = channel_details
    return channels

# parse through a sky json file and extract sid and network name
def get_channel_details(channel_details_uri, channel_names):
    response = requests.get(channel_details_uri)
    if response.status_code != 200:
        print(f"Error: {response.status_code}, {response.json().get('developerMessage')}")
        return {}

    sky_channel_details = response.json()
    if 'services' not in sky_channel_details:
        print("No 'services' key found in the response.")
        return {}

    for sky_channel_detail in sky_channel_details['services']:
        sid = sky_channel_detail["sid"]
        channel_number = sky_channel_detail["c"]
        channel_title = sky_channel_detail["t"]
        for key, value in channel_names.items():
            if channel_number == key:
                channel_names[channel_number][1] = channel_title
                channel_names[channel_number][2] = sid
    return channel_names

# retrieves the days listings as json
def get_listings(uri):
    days_listings = json.loads(requests.get(uri).content)
    return days_listings

# extracts the program details and writes to CSV with channel details repeated
def programs(days_listings, writer, channel_details):
    for schedule in days_listings['schedule']:
        channel_id = schedule['sid']
        for program in schedule['events']:
            start_time = time.strftime('%Y%m%d%H%M', time.gmtime(program['st']))
            end_time = time.strftime('%Y%m%d%H%M', time.gmtime(program['st'] + program['d']))
            title = program['t']
            desc = program.get('sy', "")
            for channel_number, details in channel_details.items():
                if details[2] == channel_id:
                    writer.writerow([channel_number, details[0], details[1], channel_id, start_time, end_time, title, desc])

# chunks the channel data up
def chunks(data, SIZE=10000):
    it = iter(data)
    for i in range(0, len(data), SIZE):
        yield {k: data[k] for k in islice(it, SIZE)}

# retrieves epg uris in batches of 10 and parses to the programs function
def get_epg_uris(channel_details, writer, days):
    for sids in chunks(channel_details, 10):
        list_of_sids = [value[2] for key, value in sids.items() if value[2]]
        string_of_sids = ','.join(list_of_sids)

        for x in range(days):
            listing_day = (datetime.datetime.now() + datetime.timedelta(days=x)).strftime('%Y%m%d')
            epg_uri = f'https://awk.epgsky.com/hawk/linear/schedule/{listing_day}/{string_of_sids}'
            day_listings = get_listings(epg_uri)
            programs(day_listings, writer, channel_details)

# initiate the grabber
def get_sky_epg_data(filename, days, region):
    channel_name_uri = "https://www.mediamole.co.uk/entertainment/broadcasting/information/sky-full-channels-list-epg-numbers-and-local-differences_441957.html"
    channel_names = get_channel_names(channel_name_uri)

    channel_details_uri = f"https://awk.epgsky.com/hawk/linear/services/4101/{region}"
    channel_details = get_channel_details(channel_details_uri, channel_names)

    with open(filename, mode='w', newline='') as file:
        writer = csv.writer(file)
        writer.writerow(["Channel Number", "Channel Name", "Network Name", "SID", "Start Time", "End Time", "Program Title", "Program Description"])

        get_epg_uris(channel_details, writer, days)

if __name__ == "__main__":
    if (len(sys.argv) == 4) and (sys.argv[2].isdigit()) and (int(sys.argv[2]) < 8) and (sys.argv[3].isdigit()):
        filename = sys.argv[1]
        days = int(sys.argv[2])
        region = int(sys.argv[3])
        get_sky_epg_data(filename, days, region)
    else:
        print("Wrong Syntax")
        print("sky_epg_grab.py <filename> <number_of_days_to_grab> <tv_region>")
        print("number_of_days_to_grab must be less than 7 days")
