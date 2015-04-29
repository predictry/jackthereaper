###Predictry Harvest Actions Log App

Keeps track of the logs and put the new ones in the queue. For processing to be inserted into NEO4j.
Also harvest the logs in json and store in s3 bucket analytics and backup

####Command
###php artisan logs:harvest

Harvest logs file:
1. push to the queue
2. set status to on queue
3. "neumann" notify through API end point and update the status.
4. aws s3 cp the log file to backup s3 dir

###php artisan logs:check
Check processed logs and update the status then backup the file into trackings-backup bucket.

###php artisan logs:parse-to-json
Parsing the log file into json formatted and deserialize the query string and push to the s3 buckets (trackings) prefix (action-logs-json-formatted)

sample:
php artisan logs:parse-to-json trackings --prefix=action-logs/ --start_date=2015-02-01 --end_date=2015-02-28

arg:
bucket

options
--prefix
--start_date
--end_date

#####API End Point
URL: http://ec2-52-74-96-43.ap-southeast-1.compute.amazonaws.com

method: PUT/PATCH
param: {FILE_NAME}
payload: {"status": "processed"}
end_point: api/v1/logs

Sample:
http://ec2-52-74-96-43.ap-southeast-1.compute.amazonaws.com/api/v1/logs/ER1VHJSBZAAAA.2014-11-21-08.c40505de.gz
payload: {"status": "processed"}
