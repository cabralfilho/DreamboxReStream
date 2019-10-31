<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use GuzzleHttp;
use GuzzleHttp\Exception\ConnectException;

class Dreambox extends Model
{
    //
    protected $fillable = ['name', 'hostname', 'port', 'username', 'password',
                           'multiple_tuners','audio_language','subtitle_language',
                           'epg_limit','dvr_length','buffer_time','exclude_bouquets',
                           'transcoding_profiles','interface_language'];

    private $guzzle_http_timeout = 5;
    private $status = null;
    private $online = false;

    private function zap_first($source)
    {
        // With multiple tuners, zapping is not needed, so the 'action' is always true/valid
        if ($this->multiple_tuners)
        {
            return true;
        }

        //Single tuner needs a zap first... :(
        $client = new GuzzleHttp\Client([
                        'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                        'timeout'  => $this->guzzle_http_timeout,
                    ]);

        if (config('app.debug'))
        {
            start_measure('zap_first','Zapping Dreambox to right channel');
        }
        try
        {
            $response = $client->request('GET', '/api/zap',[
                         'auth'  => [$this->username, $this->password],
                         'query' => ['sRef' => $source->service]
            ]);
        }
        catch (Exception $e)
        {
            $this->status = null;
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('zap_first');
        }
        return 200 == $response->getStatusCode();
    }

    private function load_playlist($source)
    {
        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);
        if (config('app.debug'))
        {
            start_measure('load_playlist','Get streaming url from playlist');
        }
        try
        {
            if ($source instanceof Channel)
            {
                $response = $client->request('GET', '/web/stream.m3u',[
                             'auth'  => [$this->username, $this->password],
                             'query' => ['ref' => $source->service]
                ]);
            }
            elseif ($source instanceof Recording)
            {
                $response = $client->request('GET', '/web/ts.m3u',[
                             'auth'  => [$this->username, $this->password],
                             'query' => ['file' =>  str_replace(' ','%20',$source->service)]
                ]);
            }
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_playlist');
        }

        if (200 == $response->getStatusCode())
        {
            $re = '/(?P<stream_url>http:\/\/' . $this->hostname . '.*)/m';
            preg_match_all($re, $response->getBody()->getContents(), $matches, PREG_SET_ORDER);
            if ($matches)
            {
                return trim($matches[0]['stream_url']);
            }
        }
        return false;
    }

    public function is_online()
    {
        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);

        if (config('app.debug'))
        {
            start_measure('is_online','Dreambox online check');
        }
        try
        {
            $response = $client->request('GET', '/api/about',['auth' => [$this->username, $this->password]]);
            $this->online = true;
        }
        catch (ConnectException $e)
        {
            $this->status = null;
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('is_online');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $this->status = json_decode($response->getBody()->getContents());

            }
            catch (Exception $e)
            {
                print_r($e);
            }
        }
        return true;
    }

    public function status()
    {
        $status = ['online' => $this->is_online(),'running' => false];
        if (!$status['online'])
        {
            return $status;
        }
        $streamer = new Streamer($this->hostname,'');
        $streamer_status = $streamer->status();

        if ($streamer_status !== false)
        {
            // Streamer is running....
            $status = Channel::where('service',$streamer_status['service'])->first();
            if (!$status) {
                $status = Recording::where('service',str_replace('%20',' ',$streamer_status['service']))->first();//->loadMissing('channel');
            }
            $status['encoder'] = $streamer_status['encoder'];
            $status['online'] = true;
            $status['type'] = (isset($status->filesize) ? 'recording' : 'channel');
            $status['running'] = true;

        }
        return $status;
    }

    public function load_bouquets($all = true)
    {
        if (!$this->online)
        {
            return false;
        }
        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);

        if (config('app.debug'))
        {
            start_measure('load_bouquets','Dreambox loading bouquets');
        }
        try
        {
            $response = $client->request('GET', '/api/getservices',['auth' => [$this->username, $this->password]]);
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_bouquets');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $data = json_decode($response->getBody()->getContents());
                $existing_bouquets = [];
                foreach($this->bouquets()->get() as $bouquet)
                {
                    $existing_bouquets[$bouquet->service] = $bouquet;
                }
                $position = 0;
                $seen_bouqets = [];
                $exclude_bouquets = array_map('trim',explode(',',strtolower($this->exclude_bouquets)));

                foreach($data->services as $bouquet_data)
                {
                    preg_match('/\\"(?P<bouquet>.*)\\"/', $bouquet_data->servicereference, $matches);
                    if ($matches)
                    {
                        if (in_array(strtolower(trim($bouquet_data->servicename)),$exclude_bouquets))
                        {
                            continue;
                        }

                        if (array_key_exists($matches['bouquet'],$existing_bouquets))
                        {
                            $bouquet = $existing_bouquets[$matches['bouquet']];
                        }
                        else
                        {
                            $bouquet = new Bouquet(['name'     => $bouquet_data->servicename,
                                                    'service'  => $matches['bouquet'],
                                                    'position' => $position++]);

                            $this->bouquets()->save($bouquet);
                        }
                        $seen_bouqets[] = $bouquet->service;
                    }
                }
            }
            catch (Exception $e)
            {
                print_r($e);
            }
            // Clean up outdated bouquets
            $this->bouquets()->whereNotIn('service',$seen_bouqets)->delete();
        }

        foreach($this->bouquets as $bouquet)
        {
            $this->load_channels($bouquet);
            $this->load_programs($bouquet);
            if (!$all)
            {
                break;
            }
        }
    }

    public function load_channels(Bouquet $bouquet)
    {
        if (!$this->online)
        {
            return false;
        }

        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);

        if (config('app.debug'))
        {
            start_measure('load_channels','Dreambox loading channels in bouquet ' . $bouquet->name);
        }
        try
        {
            $response = $client->request('GET', '/api/getservices',[
                         'auth'  => [$this->username, $this->password],
                         'query' => ['sRef' => '1:7:1:0:0:0:0:0:0:0:FROM%20BOUQUET%20%22' . $bouquet->service . '%22%20ORDER%20BY%20bouquet']
            ]);
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_channels');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $data = json_decode($response->getBody()->getContents());
                $existing_channels = [];
                foreach($this->channels()->get() as $channel)
                {
                    $existing_channels[$channel->service] = $channel;
                }
                $position = 0;
                $seen_channels = [];
                foreach($data->services as $channel_data)
                {
                    if ($channel_data->program <= 0 || '' == $channel_data->servicename) continue;

                    if (array_key_exists($channel_data->servicereference,$existing_channels))
                    {
                        $channel = $existing_channels[$channel_data->servicereference];
                    }
                    else
                    {
                        $channel = new Channel(['name'     => $channel_data->servicename,
                                                'service'  => $channel_data->servicereference]);

                        $this->channels()->save($channel);
                        $bouquet->channels()->attach($channel,['position' => $position++]);

                    }
                    $seen_channels[] = $channel->service;
                }
                // Clean up outdated channels
                // Can only delete channels that have outdated bouquets... needs to figure this out....

                //$this->channels()->whereNotIn('bouquet', $this->bouquets()->pluck('id'))->delete();
            }
            catch (Exception $e)
            {
                print_r($e);
            }
        }
    }

    public function load_programs(Bouquet $bouquet, $type = 'now')
    {
        if (!$this->online)
        {
            return false;
        }

        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);


        if (config('app.debug'))
        {
            start_measure('load_programs','Dreambox loading programs (' . $type . ') in bouquet ' . $bouquet->name);
        }
        try
        {
            $response = $client->request('GET', '/api/epg' . ('now' == $type ? 'now' : 'next') ,[
                         'auth'  => [$this->username, $this->password],
                         'query' => ['bRef' => '1:7:1:0:0:0:0:0:0:0:FROM%20BOUQUET%20%22' . $bouquet->service . '%22%20ORDER%20BY%20bouquet']
            ]);
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_programs');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $data = json_decode($response->getBody()->getContents());
                $existing_channels = [];
                foreach($this->channels()->get() as $channel)
                {
                    $existing_channels[$channel->service] = $channel;
                }

                $existing_programs = [];
                foreach($this->programs()->get() as $program)
                {
                    $existing_programs[$program->channel->name . '|' . $program->name . '|' . $program->start->timestamp] = $program;
                }

                foreach($data->events as $program_data)
                {
                    if ('' == $program_data->title || '' == $program_data->begin_timestamp || !array_key_exists($program_data->sref,$existing_channels)) continue;
                    $channel = $existing_channels[$program_data->sref];
                    if (!array_key_exists($channel->name . '|' . $program_data->title . '|' . $program_data->begin_timestamp,$existing_programs))
                    {
                        $program = new Program(['name'        => $program_data->title,
                                                'start'       => $program_data->begin_timestamp,
                                                'stop'        => $program_data->begin_timestamp + $program_data->duration_sec,
                                                'description' => $program_data->longdesc]);

                        $channel->programs()->save($program);
                    }
                }
            }
            catch (Exception $e)
            {
                print_r($e);
            }

            if ('now' == $type)
            {
                $this->load_programs($bouquet,'next');
                // Delete expired programs
                DB::table('programs')->where('stop', '<', Carbon::now())->delete();
            }
        }
    }

    public function load_epg(Channel $channel)
    {
        if (!$this->online)
        {
            return false;
        }

        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);

        // Reload the data when less then 50% of epg limit time is left....
/*
        $last_program = $channel->programs()->orderBy('start', 'desc')->first();
        if ($last_program != null && Carbon::now()->floatDiffInHours(Carbon::parse($last_program['stop'])) > ($this->epg_limit / 2.0))
        {
            return;
        }
*/
        if (config('app.debug'))
        {
            start_measure('load_epg','Dreambox loading EPG in channel ' . $channel->name);
        }
        try
        {
            $response = $client->request('GET', '/api/epgservice',[
                         'auth'  => [$this->username, $this->password],
                         'query' => ['sRef' => $channel->service]
            ]);
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_epg');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $data = json_decode($response->getBody()->getContents());

                $existing_programs = [];
                foreach($this->programs()->get() as $program)
                {
                    $existing_programs[$program->channel->name . '|' . $program->name . '|' . $program->start->timestamp] = $program;
                }

                foreach($data->events as $program_data)
                {
                    if ('' == $program_data->title || '' == $program_data->begin_timestamp) continue;

                    if (Carbon::now()->floatDiffInHours(Carbon::parse($program_data->begin_timestamp)) > $this->epg_limit) continue;

                    if (array_key_exists($channel->name . '|' . $program_data->title . '|' . $program_data->begin_timestamp,$existing_programs))
                    {
                        $program = $existing_programs[$channel->name . '|' . $program_data->title . '|' . $program_data->begin_timestamp];
                        if ('' == $program->description && '' != $program_data->longdesc)
                        {
                            $program->description = $program_data->longdesc;
                            $program->save();
                        }

                    }
                    else
                    {
                        $program = new Program(['name'        => $program_data->title,
                                                'start'       => $program_data->begin_timestamp,
                                                'stop'        => $program_data->begin_timestamp + $program_data->duration_sec,
                                                'description' => $program_data->longdesc]);

                        $channel->programs()->save($program);
                    }

                    $picon_file = Str::slug($channel->name,'_') . '.png';
                    if (!Storage::exists('public/icon/' . $picon_file)) {
                        //start_measure('load_epg_icon','Dreambox downloading picon channel ' . $channel->name);
                        $pico_response = $client->request('GET', $program_data->picon);
                        //stop_measure('load_epg_icon');
                        if (200 == $pico_response->getStatusCode())
                        {
                          Storage::put('public/icon/' . $picon_file, $pico_response->getBody());
                          $channel->picon = Storage::url('icon/' . $picon_file);
                          $channel->save();
                        }
                    }
                    else
                    {
                        $channel->picon = Storage::url('icon/' . $picon_file);
                        $channel->save();
                    }
                }
            }
            catch (Exception $e)
            {
                print_r($e);
            }

        }
    }

    public function load_recordings()
    {
        if (!$this->online)
        {
            return false;
        }

        $client = new GuzzleHttp\Client([
                            'base_uri' => 'http://' . $this->hostname . ':' . $this->port,
                            'timeout'  => $this->guzzle_http_timeout,
                        ]);

        if (config('app.debug'))
        {
            start_measure('load_recordings','Dreambox loading recordings');
        }
        try
        {
            $response = $client->request('GET', '/api/movielist',['auth' => [$this->username, $this->password]]);
        }
        catch (Exception $e)
        {
            return false;
        }
        if (config('app.debug'))
        {
            stop_measure('load_recordings');
        }

        if (200 == $response->getStatusCode())
        {
            try
            {
                $data = json_decode($response->getBody()->getContents());
                $existing_recordings = [];
                foreach($this->recordings()->get() as $recording)
                {
                    $existing_recordings[$recording->service] = $recording;
                }
                $seen_recordings = [];
                foreach($data->movies as $recording_data)
                {
                    if ($recording_data->eventname == 'epg.dat') continue;
                    if (array_key_exists($recording_data->filename,$existing_recordings))
                    {
                        $recording = $existing_recordings[$recording_data->filename];
                    }
                    else
                    {
                        $duration = explode(':',$recording_data->length);
                        if (count($duration) == 2)
                        {
                            $duration = ($duration[0] * 60) + $duration[1];
                        }
                        else
                        {
                            $duration = $duration[0];
                        }

                        $recording = new Recording(['name'        => $recording_data->eventname,
                                                    'service'     => $recording_data->filename,
                                                    'start'       => $recording_data->recordingtime,
                                                    'stop'        => $recording_data->recordingtime + $duration,
                                                    'description' => $recording_data->description,
                                                    'filesize'    => $recording_data->filesize]);

                        $this->recordings()->save($recording);

                        $channel = $this->channels()->where('name',$recording_data->servicename)->first();
                        if ($channel)
                        {
                            $channel->recordings()->save($recording);
                        }
                    }
                    $seen_recordings[] = $recording->service;
                }
            }
            catch (Exception $e)
            {
                print_r($e);
            }
        }
        // Clean up outdated recordings
        $this->recordings()->whereNotIn('service',$seen_recordings)->delete();
    }

    static public function execute($pCommand,$pLogLocation = '',$pWait = false) {
		if (($pCommand = trim($pCommand)) == "") return false;
		if ($pLogLocation == '') {
			$pCommand .= ' >/dev/null 1>/dev/null 2>/dev/null';
		} else {
			$pCommand .= ' >' . $pLogLocation . ' 1>' . $pLogLocation . '.1 2>' . $pLogLocation . '.2';
		}
		if (!$pWait) {
			$pCommand .= " & echo $!";
		}
		exec($pCommand,$pid);
		if ($pWait) {
			sleep(1);
			return -1;
		} else {
			return $pid[0]*1;
		}
	}

    public function stream($source)
    {
        $source_url = $this->load_playlist($source);
        if ($source_url === false)
        {
            return false;
        }

        if (!$this->zap_first($source))
        {
            return false;
        }

        $streamer = new Streamer($source_url,$source->name);
        if ($this->audio_language)
        {
            $streamer->language($this->audio_language);
        }
        $streamer->set_profiles($this->transcoding_profiles);
        $streamer->set_dvr($this->dvr_length);
        return $streamer->start();
    }

    public function bouquets()
    {
        return $this->hasMany('App\Bouquet')->withCount('channels')->orderBy('position');
    }

    public function channels()
    {
        return $this->hasMany('App\Channel')->withCount('programs')->orderBy('name');
    }

    public function programs()
    {
        return $this->hasManyThrough('App\Program', 'App\Channel')->with('channel')->where('stop','>',Carbon::now())->orderBy('start');
    }

    public function recordings()
    {
        return $this->hasMany('App\Recording')->orderBy('start','desc');
    }
}
