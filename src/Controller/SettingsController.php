<?php

declare(strict_types=1);

namespace App\Controller;

use App\TwitchConfig;
use App\TwitchHelper;
use App\TwitchAutomator;
use App\TwitchChannel;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Settings page
 */
class SettingsController
{

    /**
     * @var Twig
     */
    private $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function settings(Request $request, Response $response, array $args)
    {

        $sub_callback = TwitchConfig::cfg('app_url') . '/sub';

        $app_calc = "http" . ($_SERVER['SERVER_PORT'] == 443 ? 's' : '') . "://" . $_SERVER["HTTP_HOST"] . str_replace("/settings", "", $_SERVER["REQUEST_URI"]);

        $games = TwitchConfig::getGames();

        $settings = [];
        $settings['Misc'] = [];
        foreach (TwitchConfig::$settingsFields as $setting) {
            if (isset($setting['group'])) {
                if (!isset($settings[$setting['group']])) $settings[$setting['group']] = [];
                $settings[$setting['group']][] = $setting;
            } else {
                $settings['Misc'][] = $setting;
            }
        }

        return $this->twig->render($response, 'settings.twig', [
            'streamers' => TwitchConfig::getStreamers(),
            'sub_callback' => $sub_callback,
            'games' => $games,
            'settings' => $settings,
            'app_calc' => $app_calc,
            'timezones' => \DateTimeZone::listIdentifiers()
        ]);
    }

    private function generateCron()
    {

        if (!TwitchConfig::cfg('app_url')) return;

        $text = "";

        $text .= "0 5 * * 1 curl " . TwitchConfig::cfg('app_url') . "/cron/sub";
        $text .= "\n10 */12 * * * curl " . TwitchConfig::cfg('app_url') . "/cron/check_muted_vods";
        $text .= "\n0 */12 * * * curl " . TwitchConfig::cfg('app_url') . "/cron/check_deleted_vods";
        $text .= "\n";

        // $base = realpath( __DIR__ . '/../../');
        // $text .= "0 5 * * 1 php " . $base . DIRECTORY_SEPARATOR . "sub";
        // $text .= "\n0 2 * * * php " . $base . DIRECTORY_SEPARATOR . "cron" . DIRECTORY_SEPARATOR . "check_muted_vods";
        // $text .= "\n0 2 * * * php " . $base . DIRECTORY_SEPARATOR . "cron" . DIRECTORY_SEPARATOR . "check_deleted_vods";

        file_put_contents(TwitchHelper::$config_folder . DIRECTORY_SEPARATOR . 'cron.txt', $text);
    }

    /*
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

            $full_url = TwitchConfig::cfg('app_url') . '/hook';

            if (TwitchConfig::cfg('instance_id')) {
                $full_url .= '?instance=' . TwitchConfig::cfg('instance_id');
            }

            $client = new \GuzzleHttp\Client();

            try {
                $response = $client->request('GET', $full_url, ['connect_timeout' => 10, 'timeout' => 10]);
            } catch (\Throwable $th) {
                $response->getBody()->write("External app url could be contacted at all ({$full_url}).");
                return $response;
            }

            if ($response->getBody()->getContents() !== 'No data supplied') {
                $response->getBody()->write("External app url could be contacted but didn't get the expected response ({$full_url}).");
                return $response;
            }
        }

        TwitchConfig::saveConfig("settings/save");

        if ($force_new_token) {
            TwitchHelper::getAccessToken(true);
        }

        $this->generateCron();

        return $this->twig->render($response, 'dialog.twig', [
            'text' => 'Settings saved.',
            'type' => 'success'
        ]);

        // return $response->withHeader('Location', $this->router->pathFor('settings') )->withStatus(200);

    }
    */

    /*
    public function favourites_save(Request $request, Response $response, array $args)
    {

        $games                  = $_POST['games'];

        $data = [];
        foreach ($games as $id => $value) {
            $data[$id] = true;
        }

        TwitchConfig::setConfig('favourites', $data);
        TwitchConfig::saveConfig("favourites/save");

        return $this->twig->render($response, 'dialog.twig', [
            'text' => 'Favourites saved.',
            'type' => 'success'
        ]);

        return $response;
    }
    */

    /*
    public function streamer_add(Request $request, Response $response, array $args)
    {

        $username       = isset($_POST['username']) ? $_POST['username'] : null;
        $quality        = isset($_POST['quality']) ? explode(" ", $_POST['quality']) : null;
        $match          = isset($_POST['match']) ? $_POST['match'] : null;
        $download_chat  = isset($_POST['download_chat']);
        $burn_chat      = isset($_POST['burn_chat']);

        if(!$username){
            $response->getBody()->write("No username entered.");
            return $response;
        }

        if(!$quality){
            $response->getBody()->write("No quality entered. Use 'best' if you don't know better.");
            return $response;
        }

        $user_id = TwitchHelper::getChannelId($username);

        if (!$user_id) {
            $response->getBody()->write("Streamer with the username '{$username}' doesn't seem to exist on Twitch.");
            return $response;
        }

        $tmp = TwitchHelper::getChannelData($user_id);

        // fix capitalization
        if ($tmp['display_name'] !== $username) {
            $response->getBody()->write("Username capitalization seems to be incorrect, fixing.<br>");
            $username = $tmp['display_name'];
        }

        if (TwitchConfig::getStreamer($username)) {
            $response->getBody()->write("Streamer with the username '{$username}' already exists in config");
            return $response;
        }

        // template
        $streamer = [
            "username" => $username,
            "quality" => $quality
        ];

        if ($match) {
            $streamer["match"] = explode(",", $match);
        }

        if ($download_chat) $streamer["download_chat"] = true;
        if ($burn_chat) $streamer["burn_chat"] = true;

        TwitchConfig::$config['streamers'][] = $streamer;
        TwitchConfig::saveConfig("streamer/add");

        if (TwitchConfig::cfg('app_url') !== 'debug') {
            try {
                TwitchHelper::sub($username);
            } catch (\Throwable $th) {
                $response->getBody()->write("Subscription error: " . $th->getMessage());
                return $response;
            }
        }

        return $this->twig->render($response, 'dialog.twig', [
            'text' => "Streamer added: {$username}.",
            'type' => 'success'
        ]);
    }
    */

    /*
    public function streamer_update(Request $request, Response $response, array $args)
    {

        $username       = isset($_POST['username']) ? $_POST['username'] : null;
        $quality        = isset($_POST['quality']) ? explode(" ", $_POST['quality']) : null;
        $match          = isset($_POST['match']) ? $_POST['match'] : null;
        $download_chat  = isset($_POST['download_chat']);
        $burn_chat      = isset($_POST['burn_chat']);

        if (!TwitchConfig::getStreamer($username)) {
            $response->getBody()->write("Streamer with that username does not exist in config");
            return $response;
        }

        // template
        $streamer = [
            "username" => $username,
            "quality" => $quality
        ];

        if ($match) {
            $streamer["match"] = explode(",", $match);
        }

        if ($download_chat) $streamer["download_chat"] = true;
        if ($burn_chat) $streamer["burn_chat"] = true;

        if(!TwitchConfig::$config['streamers']){
            $response->getBody()->write("No streamers have been added.");
            return $response;
        }

        // todo: find a better way to do this
        $key = null;
        foreach (TwitchConfig::$config['streamers'] as $k => $v) {
            if ($v['username'] == $username) $key = $k;
        }
        if ($key === null) {
            $response->getBody()->write("Streamer {$username} not found.");
            return $response;
        }

        TwitchConfig::$config['streamers'][$key] = $streamer;
        TwitchConfig::saveConfig("streamer/update");

        if (TwitchConfig::cfg('app_url') !== 'debug') {
            try {
                TwitchHelper::sub($username);
            } catch (\Throwable $th) {
                $response->getBody()->write("Subscription error: " . $th->getMessage());
                return $response;
            }
        }

        return $this->twig->render($response, 'dialog.twig', [
            'text' => 'Streamer updated.',
            'type' => 'success'
        ]);
    }
    */

    /*
    public function streamer_delete(Request $request, Response $response, array $args)
    {

        $username = $_POST['username'];

        if (!TwitchConfig::getStreamer($username)) {
            return $this->twig->render($response, 'dialog.twig', [
                "text" => "Streamer with that username does not exist in config",
                "type" => "error"
            ]);
        }

        $streamer = new TwitchChannel();
        $streamer->load($username);
        if ($streamer->is_live) {
            return $this->twig->render($response, 'dialog.twig', [
                "text" => "Please wait until the streamer has stopped streaming before deleting.",
                "type" => "error"
            ]);
        }

        $key = null;
        foreach (TwitchConfig::$config['streamers'] as $k => $v) {
            if ($v['username'] == $username) $key = $k;
        }
        if ($key === null) {
            return $this->twig->render($response, 'dialog.twig', [
                "text" => "Streamer {$username} not found.",
                "type" => "error"
            ]);
        }

        TwitchHelper::unsub($username);

        sleep(5);

        if($streamer->getSubscription()){
            return $this->twig->render($response, 'dialog.twig', [
                "text" => "Unsubscribe failed, did not remove streamer {$username}.",
                "type" => "error"
            ]); 
        }

        unset(TwitchConfig::$config['streamers'][$key]);
        TwitchConfig::saveConfig("streamer/deleted");

        return $this->twig->render($response, 'dialog.twig', [
            "text" => "Streamer {$username} deleted.",
            "type" => "success"
        ]);
    }
    */

}
