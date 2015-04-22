###Predictry Harvest Actions Log App

Keeps track of the logs and put the new ones in the queue. For processing to be inserted into NEO4j.
Also harvest the logs in json and store in s3 bucket analytics and backup

####Command
php artisan actions:harvest (removed - not used)

php artisan logs:harvest

Harvest logs file:
1. push to the queue
2. set status to on queue
3. "neumann" notify through API end point and update the status.
4. aws s3 cp the log file to backup s3 dir


#####API End Point
URL: http://ec2-54-254-253-49.ap-southeast-1.compute.amazonaws.com/

method: PUT/PATCH
param: {FILE_NAME}
payload: {"status": "processed"}
end_point: api/v1/logs

Sample:
http://ec2-54-254-253-49.ap-southeast-1.compute.amazonaws.com/api/v1/logs/ER1VHJSBZAAAA.2014-11-21-08.c40505de.gz
payload: {"status": "processed"}
