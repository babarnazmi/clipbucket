<?php

/**
 * @Author : Arslan Hassan
 * @Since : 2012
 * @version : 3.0 
 */
$in_bg_cron = true;


//Including new conversion kit, called cb kit.
include("../includes/config.inc.php");
include("../includes/classes/conversion/conversion.class.php");

//Initializing new conversio kit
$cb_converter = new CBConverter();

$max_processes = 5;

$file_name = @$argv[1];
$file_name = @$_GET['file_name'];

while (1)
{
//Get Vido
    $queued_files = $cbupload->get_queued_files($file_name);

    define('CONV_TEST_MODE', false);

//Total Running proccesses...
    $process_running = $cbupload->conversion_count();

    if ($process_running <= $max_processes && $queued_files)
    {
        foreach ($queued_files as $queue) {
            //Creating dated folders
            $folder = $queue['file_directory'];

            $original_source = ORIGINAL_DIR . '/' . $folder . '/' . $queue['queue_name'] . '.'
                    . $queue['queue_ext'];

            $temp_source = TEMP_DIR . '/' . $queue['queue_name'] . '.' . $queue['queue_tmp_ext'];

            if (!file_exists($original_source))
            {

                if (CONV_TEST_MODE)
                    copy($temp_source, $original_source);
                else
                    rename($temp_source, $original_source);

                if (!file_exists($original_source))
                {
                    echo "Cannot make use of original file...(Err 1)";
                    $cbupload->update_queue_status($queue, 's', 'Cannot make use of original file...(Err 1)');
                } else
                {
                    //Get source information using ffmpeg and save it in our 
                    //video file database..
                    $video_info = $cb_converter->getInfo($original_source);

                    if ($video_info['has_video'] == 'no')
                    {
                        $cbupload->update_queue_status($queue, 'f', 'Invalid video file');
                    } else
                    {
                        if (!CONV_TEST_MODE)
                            $cbupload->add_video_file($queue, $video_info, 's');

                        $cbupload->update_queue_status($queue, 'started', 'Video info extracted');
                    }
                }
            }

            if (file_exists($original_source))
            {

                $video_profiles = $cbvid->get_video_profiles();
                $convert = false;
                foreach ($video_profiles as $vid_profile) {
                    if (!$cbupload->video_file_exists($queue['queue_name'], $queue['queue_id'], $vid_profile['profile_id']))
                    {

                        $convert = true;

                        $output_name = $queue['queue_name'] . $vid_profile['suffix'] . '.' . $vid_profile['ext'];
                        $output_file = VIDEOS_DIR . '/' . $folder . '/' . $output_name;

                        $log_file = $folder . '/' . $queue['queue_name'] . $vid_profile['suffix'] . '-' . $vid_profile['ext'] . '.log';


                        if (!CONV_TEST_MODE)
                            $fid = $cbupload->add_video_file($queue, array('noinfo'), 'p', $vid_profile['profile_id'], $log_file);

                        if (!CONV_TEST_MODE)
                            $cbupload->update_queue_status($queue, 'u', 'Started conversion using Profile # ' . $vid_profile['profile_id'], true);

                        $log_file = LOGS_DIR . '/' . $log_file;

                        /** All of our new conversion code is written here * */
                        $converter = new CBConverter($original_source);
                        //$converter->set_preset_path('D:\usr\local\share\ffmpeg');
                        $converter->set_log($log_file);


                        /** AS $converter is already being initited, its
                         * good to check for thumbs and if they are not
                         * generated, we should extract them first before
                         * we move on for next step
                         */
                        if (!hasThumbs($queue))
                        {
                            if (!CONV_TEST_MODE)
                                $cbupload->update_queue_status($queue, 'u', 'Generating thumbs...', false);

                            //Generate thumbnails first...then move on..
                            $thumb_sizes = get_thumb_sizes();
                            $thumbs_dir = THUMBS_DIR . '/' . $folder;

                            if ($thumb_sizes)
                            {
                                foreach ($thumb_sizes as $thumb_size) {
                                    $size = $thumb_size;
                                    $suffix = $size;

                                    $outname = $queue['queue_name'] . '-' . $suffix;

                                    if (!CONV_TEST_MODE)
                                        $cbupload->update_queue_status($queue, 'u', 'Extacting ' . $outname, false);

                                    //Using Multi Thumb Gen function..
                                    //$converter = new CBConverter($original_source);
                                    $converter->extractThumb(NULL, array(
                                        'size' => $size,
                                        'num' => 5,
                                        'increment' => true,
                                        'outname' => $outname,
                                        'outdir' => $thumbs_dir,
                                        'resize' => 'fit'
                                    ));
                                }
                            }

                            //Index thumb..
                            $cbvid->index_video_thumbs($queue['queue_name']);
                        }



                        /**
                         * @todo : Add Filters for this params 
                         */
                        $twoPass = false;
                        if ($vid_profile['2pass'] == 'yes')
                            $twoPass = true;


                        $params = array(
                            'format' => $vid_profile['format'],
                            'output_file' => $output_file,
                            'preset' => $vid_profile['preset'],
                            'height' => $vid_profile['height'],
                            'width' => $vid_profile['width'],
                            'resize' => $vid_profile['resize'],
                            'bitrate' => $vid_profile['video_bitrate'],
                            'aspect_ratio' => $vid_profile['aspect_ratio'],
                            'arate' => $vid_profile['audio_rate'],
                            'fps' => $vid_profile['video_rate'],
                            'abitrate' => $vid_profile['audio_bitrate'],
                            '2pass' => $twoPass,
                        );


                        $converter->convert($params);
                        $output_details = $converter->getInfo($output_file);
                        $time_finished = time();
                        $log = $converter->log();

                        $fields = array(
                            'log' => json_encode($log),
                            'status' => 's',
                            'output_results' => json_encode($output_details),
                            'date_completed' => $time_finished,
                        );



                        $cbupload->update_video_file($fid, $fields);

                        unset($converter);
                        break;
                    }
                }

                $file_name = $queue['queue_name'];

                if ($file_name)
                    exec(php_path() . " -q " . BASEDIR . "/actions/verify_videos.php $file_name &> /dev/null &");


                if (!$convert)
                {
                    $cbupload->update_queue_status($queue, 's', 'File removed from queue');
                }
            }

            break;
        }
    }
}
?>