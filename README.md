# skytvChannelsEPG
Fetch list of channels and corresponding schedule in CSV format

## Usage: python3 main.py ~/folder_name/filename.csv <int number_of_days> <int tv_region>

Generates csv file with following headers:
Channel Number, Channel Name, Network Name, SID, Start Time, End Time, Program Title, Program Description

The script replicates each channel's details for every program associated with that channel's SID.
