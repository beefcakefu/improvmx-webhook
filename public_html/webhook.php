<?php
// Execution time logging.
$time_start = microtime(true);

// Uncomment if not already checked in .htaccess `RewriteCond`.
/*
if (strpos($_SERVER['HTTP_USER_AGENT'], 'python-requests') === FALSE ||
	$_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(404);
	exit;
}
*/

// Load settings from ini file.
$settings = parse_ini_file('../settings.ini.php', false, INI_SCANNER_TYPED);

// Check that POST request is below size limit.
$size = (int) $_SERVER['CONTENT_LENGTH'];
if (($settings['limit'] * 1024 * 1024) < $size) {
	http_response_code(404);
	exit;
}

// Extract mailbox from REQUEST_URI, such as https://improvmx.example.com/[tom].
$mailbox = ltrim($_SERVER['REQUEST_URI'], '/');

// If accepting message for anybody, check for sane mailbox values, or else
// check mailbox against mailboxes list.
if ($settings['anybody']) {
	preg_match($settings['regex'], $mailbox, $matches);
	if (!$matches) {
		http_response_code(404);
		exit;
	}
} elseif (!in_array($mailbox, $settings['mailboxes'])) {
	http_response_code(404);
	exit;
}

// Load JSON POST request as array.
$data = json_decode(file_get_contents('php://input'), true);

// Check that ImprovMX POST request is well-formed.
if (!isset($data['timestamp']) || !isset($data['subject'])) {
	http_response_code(404);
	exit;
}

// Initiate AWS S3 client.
require '../vendor/autoload.php';
$s3 = new Aws\S3\S3Client([
	'version' => 'latest',
	'region' => $settings['region'],
	'profile' => $settings['profile']
]);

// Establish timestamp to be used in S3 folder naming.
$time = new DateTime("@{$data['timestamp']}");
$time->setTimezone(new DateTimeZone($settings['timezone']));
$path = $mailbox . $time->format('/Y/m/d/H:i:s/');

// Sanitize `subject` to be used in filename.
$filename = rawurlencode($data['subject']) . '.json';

// Try to write message to S3, placing inline images and file attachments in
// their respective subfolders. A unique id prefix is generated for each
// attachment to avoid issues when an email has more than 1 attachment with the
// same filename. If an exception is encountered, make a last attempt to write
// the whole message, including base64 encoded inline images and attachments as
// one large S3 object.
try {
	if (array_key_exists('inlines', $data)) {
		foreach ($data['inlines'] as $key => $value) {
			$object = base64_decode($value['content']);
			$s3->putObject([
				'Bucket' => $settings['bucket'],
				'Key'    => "{$path}inlines/{$value['cid']}_{$value['name']}",
				'Body' => $object
			]);
			unset($data['inlines'][$key]['content']);
		}
	}

	if (array_key_exists('attachments', $data)) {
		foreach ($data['attachments'] as $key => $value) {
			$object = base64_decode($value['content']);
			$uid = uniqid();
			$s3->putObject([
				'Bucket' => $settings['bucket'],
				'Key'    => "{$path}attachments/{$uid}_{$value['name']}",
				'Body' => $object
			]);
			unset($data['attachments'][$key]['content']);
		}
	}

	$s3->putObject([
		'Bucket' => $settings['bucket'],
		'Key'    => $path . $filename,
		'Body' => json_encode($data)
	]);
} catch (Exception $e) {
	$s3->putObject([
		'Bucket' => $settings['bucket'],
		'Key'    => $path . 'exception.json',
		'SourceFile' => 'php://input'
	]);
}

// Logging.
if ($settings['logging']) {
	$now = new DateTime('now', new DateTimeZone($settings['timezone']));
	$date = $now->format('[D M d H:i:s.u Y]');
	$size = round($size / 1024);
	$mem = round(memory_get_peak_usage() / 1024);
	$time_end = microtime(true);
	$execution_time = round(($time_end - $time_start), 3);
	error_log("{$date} Email size: {$size} KB, Peak RAM usage: {$mem} KB, " .
		"Execution time: {$execution_time} secs.\n", 3, $settings['logfile']);
}
