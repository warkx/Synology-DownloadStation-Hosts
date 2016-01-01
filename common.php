<?php
/* Copyright (c) 2011 Synology Inc. All rights reserved. */
define('ERR_UNKNOWN', 1);
define('ERR_FILEHOST_EXIST', 2);
define('ERR_INVALID_FILEHOST', 3);
define('LOGIN_FAIL', 4);
define('USER_IS_FREE', 5);
define('USER_IS_PREMIUM', 6);
define('ERR_UPATE_FAIL', 7);
define('ERR_FILE_NO_EXIST', 114);
define('ERR_REQUIRED_PREMIUM', 115);
define('ERR_NOT_SUPPORT_TYPE', 116);
define('ERR_REQUIRED_ACCOUNT', 124);
define('ERR_TRY_IT_LATER', 125);
define('ERR_TASK_ENCRYPTION', 126);
define('ERR_MISSING_PYTHON', 127);
define('ERR_PRIVATE_VIDEO', 128);
define('DEFAULT_HOST_DIR', dirname(realpath($argv[0])) . "/" . 'hosts');
define('USER_HOST_DIR', '/var/packages/DownloadStation/etc/download/userhosts');
define('USER_HOST_CONF_DIR', '/var/packages/DownloadStation/etc/download/host.conf');
define('WGET', '/var/packages/DownloadStation/target/bin/wget');
define('DOWNLOAD_STATION_USER_AGENT', "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/535 (KHTML, like Gecko) Chrome/14 Safari/535");
define('DOWNLOAD_TIMEOUT', 20);
define('DOWNLOAD_URL', 'downloadurl');
define('DOWNLOAD_FILENAME', 'filename');
define('DOWNLOAD_COUNT', 'count');
define('GET_DOWNLOAD_INFO', 'getdownloadinfo');
define('GET_FILELIST', 'getfilelist');
//-1: use input url query again, but schedule don't input waiting host name to php.
//0: don't query again
//1: use input url query again,
//2: use parse url query again
define('DOWNLOAD_ISQUERYAGAIN', 'isqueryagain');
define('DOWNLOAD_ISNEEDPOSTPROCESS', 'isneedpostprocess');
define('DOWNLOAD_ISPARALLELDOWNLOAD', 'isparalleldownload');
define('DOWNLOAD_ERROR', 'error');
define('DOWNLOAD_COOKIE', 'cookiepath');
define('DOWNLOAD_USERNAME', 'username');
define('DOWNLOAD_PASSWORD', 'password');
define('DOWNLOAD_ENABLE', 'enable');
define('DOWNLOAD_CONTINUE', 'continue');
define('DOWNLOAD_EXTRAINFO', 'extrainfo');
define('DOWNLOAD_LIST_NAME', 'list_name');
define('DOWNLOAD_LIST_FILES', 'list_files');
define('DOWNLOAD_LIST_SELECTED', 'list_selected');
define('PARAMS_DOWNLOADED_FILES', 'downloadedfiles');
define('PARAMS_PROCESS', 'downloadprocess');
define('INFO_NAME', 'name');
define('INFO_HOST_PREFIX', 'hostprefix');
define('INFO_DISPLAY_NAME', 'displayname');
define('INFO_VERSION', 'version');
define('INFO_AUTHENTICATION', 'authentication');
define('INFO_ISDOWNLOADER', 'isdownloader');
define('INFO_MODULE', 'module');
define('INFO_CLASS', 'class');
define('INFO_DESCRIPTION', 'description');
define('INFO_SUPPORTLIST', 'supporttasklist');
define('CURL_OPTION_SAVECOOKIEFILE', 'SaveCookieFile');
define('CURL_OPTION_LOADCOOKIEFILE', 'LoadCookieFile');
define('CURL_OPTION_POSTDATA', 'PostData');
define('CURL_OPTION_COOKIE', 'Cookie');
define('CURL_OPTION_HTTPHEADER', 'HttpHeader');
define('CURL_OPTION_FOLLOWLOCATION', 'FollowLocation');
define('CURL_OPTION_HEADER', 'Header');

function LogError($msg) {
	openlog($argv[0], LOG_PID, LOG_USER);
	syslog(LOG_ERR, $msg);
	closelog();
}

function LogInfo($msg) {
	openlog($argv[0], LOG_PID, LOG_USER);
	syslog(LOG_INFO, $msg);
	closelog();
}

function strposOffset($search, $string, $offset)
{
	$arr = explode($search, $string);
	switch( $offset ) {
		case $offset == 0:
		return false;
		break;

		case $offset > max(array_keys($arr)):
		return false;
		break;

		default:
		return strlen(implode($search, array_slice($arr, 0, $offset)));
    }
}

function EscapeChange($str) {
	$patternarray = array("\"", "*", "/", ":", "<", "=", ">", "?", "\\\\", "|");
	$str = str_replace($patternarray, "_", $str);
	return $str;
}

function parse_cookiefile($file) {
	$aCookies = array();
	$aLines = file($file);
	foreach($aLines as $line){
		if('#'==$line{0})
		continue;
		$arr = explode("\t", $line);
		if(isset($arr[5]) && isset($arr[6]))
			$aCookies[$arr[5]] = $arr[6];
	}

	return $aCookies;
}

function GenerateCurl($Url, $Option=NULL)
{
	$ret = FALSE;

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, DOWNLOAD_TIMEOUT);
	curl_setopt($curl, CURLOPT_TIMEOUT, DOWNLOAD_TIMEOUT);
	curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
	if (NULL != $Option) {
		if (!empty($Option[CURL_OPTION_POSTDATA])) {
			$PostData = http_build_query($Option[CURL_OPTION_POSTDATA]);
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $PostData);
		}
		if (!empty($Option[CURL_OPTION_COOKIE])) {
			curl_setopt($curl, CURLOPT_COOKIE, $Option[CURL_OPTION_COOKIE]);
		}
		if (!empty($Option[CURL_OPTION_HTTPHEADER])) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $Option[CURL_OPTION_HTTPHEADER]);
		}
		if (!empty($Option[CURL_OPTION_SAVECOOKIEFILE])) {
			curl_setopt($curl, CURLOPT_COOKIEJAR, $Option[CURL_OPTION_SAVECOOKIEFILE]);
		}
		if (!empty($Option[CURL_OPTION_LOADCOOKIEFILE])) {
			curl_setopt($curl, CURLOPT_COOKIEFILE, $Option[CURL_OPTION_LOADCOOKIEFILE]);
		}
		if (!empty($Option[CURL_OPTION_FOLLOWLOCATION]) && TRUE == $Option[CURL_OPTION_FOLLOWLOCATION]) {
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
		}
		if (!empty($Option[CURL_OPTION_HEADER])&& TRUE == $Option[CURL_OPTION_HEADER]) {
			curl_setopt($curl, CURLOPT_HEADER, TRUE);
		}
	}
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($curl, CURLOPT_URL, $Url);
	$ret = $curl;

	return $ret;
}

?>
