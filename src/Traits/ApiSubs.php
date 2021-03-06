<?php

declare(strict_types=1);

namespace App\Traits;

use Slim\Psr7\Request;
use Slim\Psr7\Response;

use App\TwitchConfig;
use App\TwitchHelper;
use App\TwitchAutomatorJob;

use Symfony\Component\Process\Process;

trait ApiSubs
{

    public function subscriptions_sub(Request $request, Response $response, $args)
    {

        $streamers = TwitchConfig::getStreamers();

        $payload_data = [
            'channels' => []
        ];

        foreach ($streamers as $k => $v) {

            $entry = [];
            $username = $v['username'];
            $entry['username'] = $username;
            $ret = TwitchHelper::sub($username);

            if ($ret === true) {
                $entry['status'] = 'Subscription request sent, check logs for details';
            } else {
                $entry['status'] = "Error: {$ret}";
            }

            $payload_data['channels'][] = $entry;
        }

        if (count($streamers) == 0) {
            $response->getBody()->write(json_encode([
                "message" => "No channels to subscribe to.",
                "status" => "ERROR"
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode([
            "data" => $payload_data,
            "status" => "OK"
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function subscriptions_list(Request $request, Response $response, $args)
    {

        $subs = TwitchHelper::getSubs();

        if (isset($subs['total']) && $subs['total'] > 0) {

            // $response->getBody()->write("Total: {$subs['total']}<br>");

            $all_usernames = [];

            $payload_data = [
                'channels' => [],
                'total' => $subs['total']
            ];

            foreach ($subs['data'] as $data) {

                $entry = [];

                $user_id = explode("=", $data['topic'])[1];

                $u = TwitchHelper::getChannelUsername($user_id);

                if (!$u) {
                    $entry['user_id'] = $user_id;
                    $entry['error'] = "Could not get username, did it not get cached?";
                    $payload_data['channels'][] = $entry;
                    continue;
                }

                $user_data = TwitchHelper::getChannelData($user_id);
                $username = $user_data['display_name'];

                $entry['username']          = $username;
                $entry['user_id']           = $user_id;
                $entry['topic']             = $data['topic'];
                $entry['callback']          = $data['callback'];
                $entry['instance_match']    = $data['callback'] == TwitchConfig::cfg('app_url') . '/hook' . (TwitchConfig::cfg('instance_id') ? '?instance=' . TwitchConfig::cfg('instance_id') : '');
                $entry['expires_at']        = $data['expires_at'];
                $entry['already_exists']    = isset($all_usernames[mb_strtolower($username)]);

                $all_usernames[mb_strtolower($username)] = true;

                $payload_data['channels'][] = $entry;
            }

            $payload_data['all_usernames'] = $all_usernames;

            $response->getBody()->write(json_encode([
                "data" => $payload_data,
                "status" => "OK"
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } elseif (isset($subs['total']) && $subs['total'] == 0) {
            $response->getBody()->write(json_encode([
                "message" => "No subscriptions. Let cron do its job or visit the sub page.",
                "status" => "ERROR"
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write(json_encode([
                "message" => "Data error.",
                "data" => json_encode($subs),
                "status" => "ERROR"
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
