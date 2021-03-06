<?php

namespace App\Traits;

use Slim\Psr7\Request;
use Slim\Psr7\Response;

use App\TwitchConfig;
use App\TwitchHelper;

trait ApiSettings
{

    public function settings_list(Request $request, Response $response, array $args)
    {

        $config = [];
        foreach (TwitchConfig::$config as $key => $value) {
            if (isset(TwitchConfig::getSettingField($key)['secret']) /* || $key == 'streamers' || $key == 'favourites'*/) {
                continue;
            }
            $config[$key] = $value;
        }

        $fields = [];
        foreach (TwitchConfig::$settingsFields as $key => $value) {
            $fields[$value['key']] = $value;
        }

        $fields['timezone']['choices'] = \DateTimeZone::listIdentifiers(); // static

        $package_path = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "package.json";
        if (file_exists($package_path)) {
            $version = json_decode(file_get_contents($package_path))->version;
        } else {
            $version = '?';
        }

        $payload = json_encode([
            'data' => [
                'config' => $config,
                'fields' => $fields,
                'version' => $version
            ],
            'status' => 'OK'
        ]);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function settings_save(Request $request, Response $response, array $args)
    {

        $force_new_token = false;
        if (TwitchConfig::cfg('api_client_id') !== $_POST['api_client_id']) {
            $force_new_token = true;
        }

        foreach (TwitchConfig::$settingsFields as $setting) {

            $key = $setting['key'];

            if ($setting['type'] == "boolean") {

                TwitchConfig::setConfig($key, isset($_POST[$key]));
            } else {

                if (isset($setting['secret'])) {
                    if ($_POST[$key]) {
                        TwitchConfig::setConfig($key, $_POST[$key]);
                    }
                } else {
                    TwitchConfig::setConfig($key, $_POST[$key]);
                }
            }
        }

        if (TwitchConfig::cfg('app_url')) {

            $full_url = TwitchConfig::cfg('app_url') . '/api/v0/hook';

            if (TwitchConfig::cfg('instance_id')) {
                $full_url .= '?instance=' . TwitchConfig::cfg('instance_id');
            }

            $client = new \GuzzleHttp\Client();

            try {
                $resp = $client->request('GET', $full_url, ['connect_timeout' => 10, 'timeout' => 10]);
            } catch (\Throwable $th) {
                $response->getBody()->write(json_encode([
                    "message" => "External app url could be contacted at all ({$full_url}).",
                    "status" => "ERROR"
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            if ($resp->getBody()->getContents() !== 'No data supplied') {
                $response->getBody()->write(json_encode([
                    "message" => "External app url could be contacted but didn't get the expected response ({$full_url}).",
                    "status" => "ERROR"
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        TwitchConfig::saveConfig("settings/save");

        if ($force_new_token) {
            TwitchHelper::getAccessToken(true);
        }

        $response->getBody()->write(json_encode([
            "message" => "Settings saved.",
            "status" => "OK"
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

        // return $response->withHeader('Location', $this->router->pathFor('settings') )->withStatus(200);

    }
}
