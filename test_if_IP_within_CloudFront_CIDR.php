#!/usr/bin/env php
<?php

// =============================================================================================
// test_if_IP_within_CloudFront_CIDR.php
// 
// By Stefan Wuensch Dec. 2015
//
// This script takes one or more IPv4 addresses and checks each one to see if it falls within
// an allowed CIDR block of Amazon Web Service (AWS) CloudFront.
// This can be used to ensure that web clients of an application are only accessing the app
// through CloudFront, instead of hitting the ELB directly.
// 
// NOTE: Command line arguments take precedence over STDIN. If both STDIN and args are 
// given, STDIN will be ignored.
// 
// 
// Usage with arguments:
// test_if_IP_within_CloudFront_CIDR.php IPv4address [ IPv4address IPv4address IPv4address ... ]
//
// Usage with STDIN:
// some-command-generating-IPv4addresses | test_if_IP_within_CloudFront_CIDR.php
// Multiple addresses per line are acceptable if delimited by commas or common whitespace
// characters like " ", "\n", "\t"
// 
// 
// Output:
// - If IP is found in AWS CloudFront CIDR: 
// 	OK - CloudFront IP: [ the IP address ]
// - If IP is not found in AWS CloudFront CIDR:
// 	Bad - not CloudFront IP: [ the IP address ]
// 
// Example output:
// Bad - not CloudFront IP:  5.175.193.164
// Bad - not CloudFront IP:  37.187.99.73
// OK - CloudFront IP:  54.182.204.72
// OK - CloudFront IP:  54.182.204.74
// 
//
// Required: Network connectivity to reach Amazon AWS to query $aws_IP_ranges_URL
// 
//
// Full example of use: Download an ELB log to a local file - in this example "ELBlog.txt".
// Take the third field of the log (which is the client IP address) with 'awk'
// and drop the port number with 'cut' then sort and make them unique before
// feeding to this script. Finally, 'grep' out the "OK" addresses and show only those
// which don't fall within CloudFront CIDR blocks.
// % awk '{print $3}' ELBlog.txt | cut -d: -f1 | sort -n | uniq | ./test_if_IP_within_CloudFront_CIDR.php | grep -v OK
// Bad - not CloudFront IP:  5.175.193.164
// Bad - not CloudFront IP:  37.187.99.73
// Bad - not CloudFront IP:  54.80.106.124
// Bad - not CloudFront IP:  61.135.189.125
// Bad - not CloudFront IP:  61.160.247.231
// Bad - not CloudFront IP:  62.210.88.201
// Bad - not CloudFront IP:  66.249.66.2
// 
// =============================================================================================


error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );
date_default_timezone_set('America/New_York');


// This URL is the authoritative list of all CIDR blocks used by AWS
$aws_IP_ranges_URL = "https://ip-ranges.amazonaws.com/ip-ranges.json" ;



// =============================================================================================
// This function from http://stackoverflow.com/questions/10243594/find-whether-a-given-ip-exists-in-cidr-or-not
// 
// Usage: 
// testifIPwithinCIDR( IPv4address, array_of_CIDR )
// 
// Example: 
// testifIPwithinCIDR( "54.182.204.77", array( '54.239.192.0/19', '204.246.176.0/20', '204.246.174.0/23' ) )
// 
// Returns:
// 	True if IPv4address falls within any member of array_of_CIDR
// 	False if IPv4address does not fall within all members of array_of_CIDR
// 
function testifIPwithinCIDR( $ip_to_check, $cidrs ) {
	$ip_parts = explode( '.', $ip_to_check ) ;
	foreach ( $ip_parts as &$v )
		$v = str_pad( decbin( $v ), 8, '0', STR_PAD_LEFT ) ;
	$ip_parts = join( '', $ip_parts ) ;
	$result = false ;
	foreach ( $cidrs as $cidr ) {
		$parts = explode( '/', $cidr ) ;
		$cidr_parts = explode( '.', $parts[ 0 ] ) ;
		foreach ( $cidr_parts as &$v ) 
			$v = str_pad( decbin( $v ), 8, '0', STR_PAD_LEFT ) ;
		$cidr_parts = substr( join( '', $cidr_parts ), 0, $parts[1] ) ;
		$ipux = substr( $ip_parts, 0, $parts[ 1 ] ) ;
		$result = ( $cidr_parts === $ipux ) ;
		if ( $result ) break ;
	}
	return $result ;
}
// =============================================================================================



// =============================================================================================
// This simply calls testifIPwithinCIDR and decides what to output
// Same usage as testifIPwithinCIDR() above
function printResult( $oneAddress, $CIDRarray ) {

	if ( ! isValidIPv4( $oneAddress ) ) {
		echo 'Error - not a valid IPv4 address - got: ' . $oneAddress . "\n" ;
		return ;
	}

	echo ( testifIPwithinCIDR( $oneAddress, $CIDRarray ) 
			? 'OK - CloudFront IP: ' 
			: 'Bad - not CloudFront IP: ' 
		) . $oneAddress . "\n" ;
}
// =============================================================================================



// =============================================================================================
// Validate if it's an IPv4 address and return True if it is, False otherwise
function isValidIPv4( $checkThis ) {
	return ( filter_var( $checkThis, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) ;
}
// =============================================================================================



// Set up stream context for HTTP query. 
// Required if HTTP Proxy is required to reach AWS
$streamContext = null ;
if ( preg_match( "/sites|webroots/i", gethostname() ) ) {	// Add any additional hostname matches to this regex as needed

	// http://us3.php.net/manual/en/function.file-get-contents.php
	$myContext = array(
		'http' => array(
			'proxy' => 'tcp://fqdn.of.your.proxy:8888',	// Change this as needed for whatever proxy service is being used
			'request_fulluri' => true
		)
	) ;
	$streamContext = stream_context_create( $myContext );
}


// Example of what $OK_CIDRs should look like:
// $OK_CIDRs = array( '54.239.192.0/19', '204.246.176.0/20', '204.246.174.0/23' ) ;

// Now we go out to AWS, snag their JSON blob of address blocks, and stuff them into an array
$OK_CIDRs_JSON = json_decode( file_get_contents( $aws_IP_ranges_URL, false, $streamContext ) ) ;
if ( ! isset( $OK_CIDRs_JSON ) || $OK_CIDRs_JSON == "" ) {
	print "Error - can't get allowed CIDR list from $aws_IP_ranges_URL\n" ;
	exit( 1 ) ;
}
$OK_CIDRs = array() ;
foreach ( $OK_CIDRs_JSON -> prefixes as $CIDRblock ) {
	if ( $CIDRblock -> service == "CLOUDFRONT" ) {			// We only want CIDR blocks that are CloudFront
		array_push( $OK_CIDRs, $CIDRblock -> ip_prefix ) ;	// Add it to the array
	}
}
// var_dump( $OK_CIDRs ) ; exit ;	// For debugging validation
// Example of var_dump - only the first 6 out of 17:
// array(17) {
//   [0]=>
//   string(12) "52.84.0.0/15"
//   [1]=>
//   string(13) "54.182.0.0/16"
//   [2]=>
//   string(13) "54.192.0.0/16"
//   [3]=>
//   string(13) "54.230.0.0/16"
//   [4]=>
//   string(15) "54.239.128.0/18"
//   [5]=>
//   string(15) "54.239.192.0/19"




if ( $argc > 1 && $argv[ 1 ] ) {	// This means we have command-line args

	foreach ( $argv as $test_ip ) {
		if ( $test_ip == $argv[ 0 ] ) continue ;	// $argv[ 0 ] is the name of this script, so skip it
		if ( $test_ip == "" ) continue ;		// Don't know how we'd get a null arg, but just in case skip it
		printResult( $test_ip, $OK_CIDRs ) ;
	}

} else {	// If we didn't get command-line args, assume we have STDIN. (Just like 'grep' if there's no args _and_ no STDIN, how can you tell?)

	while( !feof( STDIN ) ) {
		$test_line = rtrim( fgets( STDIN ) ) ;				// Trim off EOL
		if ( $test_line == "" ) continue ;				// Skip a blank line
		foreach( preg_split( "/[\s,]+/", $test_line ) as $test_ip ) {	// In case we have multiple addresses per line, handle each one
			printResult( $test_ip, $OK_CIDRs ) ;
		}
	}

}

?>
