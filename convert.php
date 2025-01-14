<?php
require_once __DIR__ . '/vendor/autoload.php';

use MichaelBelgium\YoutubeConverter\Config;
use YoutubeDl\YoutubeDl;

header("Content-Type: application/json");

const POSSIBLE_FORMATS = ['mp3', 'mp4'];

if(isset($_GET["youtubelink"]) && !empty($_GET["youtubelink"]))
{
	$youtubelink = $_GET["youtubelink"];
	$format = $_GET['format'] ?? 'mp3';

	if(!in_array($format, POSSIBLE_FORMATS))
		die(json_encode(array("error" => true, "message" => "Invalid format: only mp3 or mp4 are possible")));

	$success = preg_match('#(?<=v=)[a-zA-Z0-9-]+(?=&)|(?<=v\/)[^&\n]+|(?<=v=)[^&\n]+|(?<=youtu.be/)[^&\n]+#', $youtubelink, $matches);

	if(!$success)
		die(json_encode(array("error" => true, "message" => "No video specified")));

	$id = $matches[0];

	$exists = file_exists(Config::DOWNLOAD_FOLDER.$id.".".$format);

	if(Config::DOWNLOAD_MAX_LENGTH > 0 || $exists)
	{
		$dl = new YoutubeDl(['skip-download' => true]);
		$dl->setBinPath('/usr/bin/youtube-dl');
		$dl->setDownloadPath(Config::DOWNLOAD_FOLDER);
	
		try	{
			$video = $dl->download($youtubelink);
	
			if($video->getDuration() > Config::DOWNLOAD_MAX_LENGTH && Config::DOWNLOAD_MAX_LENGTH > 0)
				throw new Exception("The duration of the video is {$video->getDuration()} seconds while max video length is ".Config::DOWNLOAD_MAX_LENGTH." seconds.");
		}
		catch (Exception $ex)
		{
			die(json_encode(array("error" => true, "message" => $ex->getMessage())));
		}
	}

	if(!$exists)
	{
		if($format == 'mp3')
		{
			$options = array(
				'extract-audio' => true,
				'audio-format' => 'mp3',
				'audio-quality' => 0,
				'output' => '%(id)s.%(ext)s',
				//'ffmpeg-location' => '/usr/local/bin/ffmpeg'
			);
		}
		else
		{
			$options = array(
				'continue' => true,
				'format' => 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
				'output' => '%(id)s.%(ext)s'
			);
		}

		$dl = new YoutubeDl($options);
		$dl->setDownloadPath(Config::DOWNLOAD_FOLDER);
	}

	try
	{
		$url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF'])."/".Config::DOWNLOAD_FOLDER;
		if($exists)
			$file = $url.$id.".".$format;
		else 
		{
			$video = $dl->download($youtubelink);
			$file = $url.$video->getFilename();
		}

		$json = json_encode(array(
			"error" => false,
			"youtube_id" => $video->getId(),
			"title" => $video->getTitle(),
			"alt_title" => $video->getAltTitle(),
			"duration" => $video->getDuration(),
			"file" => $file,
			"uploaded_at" => $video->getUploadDate()
		));

		if(Config::LOG)
		{
			$now = new DateTime();
			$file = fopen('logs/'.$now->format('Ymd').'.log', 'a');
			fwrite($file, '[' . $now->format('H:i:s') . '] ' . $json . PHP_EOL);
			fclose($file);
		}

		echo $json;
	}
	catch (Exception $e)
	{
		echo json_encode(array("error" => true, "message" => $e->getMessage()));
	}
}
else if(isset($_GET["delete"]) && !empty($_GET["delete"]))
{
	$id = $_GET["delete"];
	$format = $_GET["format"] ?? POSSIBLE_FORMATS;

	if(empty($format))
		$format = POSSIBLE_FORMATS;

	if(!is_array($format))
		$format = [$format];

	$removedFiles = [];

	foreach($format as $f) {
		$localFile = Config::DOWNLOAD_FOLDER.$id.".".$f;
		if(file_exists($localFile)) {
			unlink($localFile);
			$removedFiles[] = $f;
		}
	}
	
	$resultNotRemoved = array_diff(POSSIBLE_FORMATS, $removedFiles);

	if(empty($removedFiles))
		$message = 'No files removed.';
	else
		$message = 'Removed files: ' . implode(', ', $removedFiles) . '.';

	if(!empty($resultNotRemoved))
		$message .= ' Not removed: ' . implode(', ', $resultNotRemoved);

	echo json_encode(array(
		"error" => false,
		"message" => $message
	));
}
else
	echo json_encode(array("error" => true, "message" => "Invalid request"));
?>
