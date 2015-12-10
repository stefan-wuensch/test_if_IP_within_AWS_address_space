# test_if_IP_within_CloudFront_CIDR
A PHP script to test IPv4 addresses for whether they are within AWS CloudFront.

By Stefan Wuensch Dec. 2015

This script takes one or more IPv4 addresses and checks each one to see if it falls within
an allowed CIDR block of Amazon Web Service (AWS) CloudFront.
This can be used to ensure that web clients of an application are only accessing the app
through CloudFront, instead of hitting the ELB directly.

**NOTE**: Command line arguments take precedence over STDIN. If both STDIN and args are 
given, STDIN will be ignored.


Usage with arguments:
```
test_if_IP_within_CloudFront_CIDR.php IPv4address [ IPv4address IPv4address IPv4address ... ]
```

Usage with STDIN:
```
some-command-generating-IPv4addresses | test_if_IP_within_CloudFront_CIDR.php
```
Multiple addresses per line are acceptable if delimited by commas or common whitespace
characters like `" "`, `"\n"`, `"\t"`


Output:
- If IP is found in AWS CloudFront CIDR: 
```
	OK - CloudFront IP: [ the IP address ]
```
- If IP is not found in AWS CloudFront CIDR:
```
	Bad - not CloudFront IP: [ the IP address ]
```

Example output:
```
Bad - not CloudFront IP:  5.175.193.164
Bad - not CloudFront IP:  37.187.99.73
OK - CloudFront IP:  54.182.204.72
OK - CloudFront IP:  54.182.204.74
```


**Required**: Network connectivity to reach Amazon AWS to download via HTTP - see `$aws_IP_ranges_URL`

As of 2015-12-10 the URL is https://ip-ranges.amazonaws.com/ip-ranges.json


__Full example of use__: Download an ELB log to a local file - in this example `ELBlog.txt`.
Take the third field of the log (which is the client IP address) with `awk`
and drop the port number with `cut`, then `sort` and make them unique (`uniq`) before
feeding to this script. Finally, `grep` out the "OK" addresses and show only those
which don't fall within CloudFront CIDR blocks.
```
% awk '{print $3}' ELBlog.txt | cut -d: -f1 | sort -n | uniq | ./test_if_IP_within_CloudFront_CIDR.php | grep -v OK
Bad - not CloudFront IP:  5.175.193.164
Bad - not CloudFront IP:  37.187.99.73
Bad - not CloudFront IP:  54.80.106.124
Bad - not CloudFront IP:  61.135.189.125
Bad - not CloudFront IP:  61.160.247.231
Bad - not CloudFront IP:  62.210.88.201
Bad - not CloudFront IP:  66.249.66.2
```
