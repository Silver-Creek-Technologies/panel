<?php

namespace Pterodactyl\Events\Server;

use Pterodactyl\Events\Event;
use Pterodactyl\Models\Server;
use Illuminate\Queue\SerializesModels;

class Creating extends Event
{
    use SerializesModels;

    /**
     * Sends a request to the NW Central Server Auto-verified hoster API, requesting to add the server to the server list.
     *
     * @param string $ip
     * @param int $port
     * @return bool
     */
    public function sendRequest(string $ip, int $port): bool
    {
        if ($ip == "")
            die("Invalid hashmap result");

        $url = 'https://api.scpslgame.com/provider/manageserver.php';

        /*
         * Fill in the 'user' and 'token' values with those of the NW auto-verified API credentials we are given.
         */
        $data = array(
            'user' => '',
            'token' => '',
            'ip' => $ip,
            'port' => $port,
            'action' => 'register'
        );

        if ($data["user"] == '')
            die("You didn't listen to Joker");

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($curl);
        $file = fopen("/var/www/pterodactyl/storage/logs/creating.log", "a");
        fwrite($file, date("Y-m-d h:m:s", time()) . "\n");
        fwrite($file, "$ip\n");
        fwrite($file, "$port\n");
        fwrite($file, "$result\n");
        fclose($file);

        return $result == "OK";
    }

    /**
     * @param string $alias
     * @return string
     */
    private function getIp(string $alias): string
    {
        /*
         * Fill in the map with 'aliasName => 'x.x.x.x' for each server node. aliasName must be the full "IP Alias" value from the ptero panel.
         */
        $aliasMap = array();

        if (array_key_exists($alias, $aliasMap))
            return $aliasMap[$alias];
        return "";
    }

    /**
     * Attempts to add the server to the SL server list.
     *
     * @param Server $server
     * @return bool
     */
    private function tryAddToServerList(Server $server): bool
    {
        $node = $server->allocation->ip_alias;
        $port = $server->allocation->port;
        $ip = $this->getIp($node);
        return $this->sendRequest($ip, $port, false);
    }

    /**
     * Create a new event instance.
     */
    public function __construct(public Server $server)
    {
        if ($server->nest_id == 6)
            $this->tryAddToServerList($server);
    }
}
